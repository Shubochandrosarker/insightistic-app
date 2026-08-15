<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiCitation;
use App\Models\AiFeedback;
use App\Models\AiMessage;
use App\Models\AiRun;
use App\Models\AiThread;
use App\Models\Site;
use App\Services\Copilot\CopilotChat;
use App\Services\Copilot\CopilotContextBuilder;
use App\Services\UsageService;
use App\Support\DateRange;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Insightistic AI Copilot (spec Phase 5): threads, SSE message streaming,
 * citations, feedback. Tenancy: every model here uses the organization
 * global scope; threads are additionally personal (user_id) unless shared.
 */
class CopilotController extends Controller
{
    public function __construct(
        private CopilotContextBuilder $contextBuilder,
        private CopilotChat $chat,
        private UsageService $usage,
        private Tenancy $tenancy,
    ) {}

    public function threads(Request $request)
    {
        $threads = AiThread::query()
            ->where('user_id', $request->user()->id)
            ->when(! $request->boolean('include_archived'), fn ($q) => $q->whereNull('archived_at'))
            ->orderByDesc('last_message_at')
            ->limit(50)
            ->get(['id', 'title', 'site_id', 'context_json', 'archived_at', 'last_message_at', 'created_at']);

        return response()->json(['threads' => $threads]);
    }

    public function createThread(Request $request)
    {
        $data = $request->validate([
            'title'   => ['nullable', 'string', 'max:200'],
            'site_id' => ['nullable', 'integer'],
        ]);

        $siteId = null;
        if (! empty($data['site_id'])) {
            $site = Site::find($data['site_id']); // tenant-scoped resolve
            if (! $site) {
                return response()->json(['message' => 'Site not found.'], 404);
            }
            $siteId = $site->id;
        }

        $thread = AiThread::create([
            'user_id'         => $request->user()->id,
            'site_id'         => $siteId,
            'title'           => $data['title'] ?? 'New conversation',
            'last_message_at' => now(),
        ]);

        return response()->json(['thread' => $thread], 201);
    }

    public function showThread(Request $request, int $id)
    {
        $thread = $this->findThread($request, $id);
        if (! $thread) {
            return response()->json(['message' => 'Thread not found.'], 404);
        }

        return response()->json([
            'thread'   => $thread,
            'messages' => $thread->messages()
                ->with(['citations' => fn ($q) => $q->select(['id', 'ai_message_id', 'knowledge_chunk_id', 'knowledge_source_id', 'source_type', 'title', 'url', 'snippet', 'score', 'data_date', 'last_indexed_at'])])
                ->get(['id', 'role', 'content', 'answer_json', 'status', 'error_code', 'created_at']),
        ]);
    }

    public function updateThread(Request $request, int $id)
    {
        $thread = $this->findThread($request, $id);
        if (! $thread) {
            return response()->json(['message' => 'Thread not found.'], 404);
        }

        $data = $request->validate([
            'title'    => ['sometimes', 'string', 'max:200'],
            'archived' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('archived', $data)) {
            $thread->archived_at = $data['archived'] ? now() : null;
        }
        if (isset($data['title'])) {
            $thread->title = $data['title'];
        }
        $thread->save();

        return response()->json(['thread' => $thread]);
    }

    public function deleteThread(Request $request, int $id)
    {
        $thread = $this->findThread($request, $id);
        if (! $thread) {
            return response()->json(['message' => 'Thread not found.'], 404);
        }

        $thread->delete(); // messages/runs/citations/feedback cascade

        return response()->json(['message' => 'Thread deleted.']);
    }

    /**
     * POST /copilot/threads/{id}/messages — SSE stream.
     * Events: run.started, delta {text}, citations {citations[]},
     * run.completed {message}, run.failed {message,code}.
     */
    public function sendMessage(Request $request, int $id)
    {
        $thread = $this->findThread($request, $id);
        if (! $thread) {
            return response()->json(['message' => 'Thread not found.'], 404);
        }

        $org = $this->tenancy->organization();

        $data = $request->validate([
            'content'            => ['required', 'string', 'max:8000'],
            'site_id'            => ['nullable', 'integer'],
            'period'             => ['nullable', 'string', 'max:30'],
            'from'               => ['nullable', 'date'],
            'to'                 => ['nullable', 'date'],
            'sources'            => ['nullable', 'array'],
            'sources.*'          => [Rule::in(\App\Services\ContextAssembler::TYPES)],
            'knowledge_base_ids' => ['nullable', 'array'],
            'knowledge_base_ids.*' => ['integer'],
        ]);

        // Server-side quota — structured 402 before any provider call.
        if (! $this->usage->canSendAiMessage($org)) {
            return response()->json(
                $this->usage->limitError($org, 'monthly_ai_messages', (int) $this->usage->counter($org)->ai_messages_used),
                402,
            );
        }

        // Resolve the authorized context selections.
        $site = null;
        if (! empty($data['site_id'])) {
            $site = Site::find($data['site_id']); // tenant-scoped; foreign id = 404
            if (! $site) {
                return response()->json(['message' => 'Site not found.'], 404);
            }
        } elseif ($thread->site_id) {
            $site = Site::find($thread->site_id);
        }

        $range = null;
        if ($site) {
            $range = DateRange::fromRequest(
                $data['period'] ?? 'last_30_days',
                $data['from'] ?? null,
                $data['to'] ?? null,
                $site->timezone ?: config('app.timezone', 'UTC'),
            );
        }

        // Knowledge-base ids are validated against the tenant scope: any id
        // that is not this org's simply disappears from the filter.
        $kbIds = [];
        if (! empty($data['knowledge_base_ids'])) {
            $kbIds = \App\Models\KnowledgeBase::whereIn('id', $data['knowledge_base_ids'])->pluck('id')->all();
        }

        $userMessage = AiMessage::create([
            'ai_thread_id' => $thread->id,
            'user_id'      => $request->user()->id,
            'role'         => 'user',
            'content'      => $data['content'],
            'status'       => 'completed',
        ]);

        $assistantMessage = AiMessage::create([
            'ai_thread_id' => $thread->id,
            'role'         => 'assistant',
            'content'      => '',
            'status'       => 'streaming',
        ]);

        $thread->forceFill([
            'last_message_at' => now(),
            'title'           => $thread->title === 'New conversation'
                ? mb_substr($data['content'], 0, 80)
                : $thread->title,
            'context_json'    => [
                'site_id'            => $site?->id,
                'period'             => $data['period'] ?? null,
                'sources'            => $data['sources'] ?? null,
                'knowledge_base_ids' => $kbIds,
            ],
        ])->save();

        $context = $this->contextBuilder->build($org, [
            'site'               => $site,
            'date_range'         => $range,
            'sources'            => $data['sources'] ?? ['executive'],
            'knowledge_base_ids' => $kbIds,
            'question'           => $data['content'],
        ]);

        return new StreamedResponse(function () use ($org, $data, $context, $assistantMessage, $range) {
            $emit = function (string $event, array $payload) {
                echo "event: {$event}\n";
                echo 'data: ' . json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            $emit('run.started', ['message_id' => $assistantMessage->id]);

            $startedAt = microtime(true);

            try {
                $result = $this->chat->answer(
                    $org,
                    $data['content'],
                    $context['prompt_context'],
                    fn (string $text) => $emit('delta', ['text' => $text]),
                );

                // Citations: ONLY from the retrieval set the server built.
                // citation_refs from the model filter which retrieved items
                // are shown; unknown refs are dropped, never invented.
                $refs = collect($result['answer_json']['citation_refs'] ?? [])
                    ->filter(fn ($r) => is_string($r))->values();
                $citations = [];
                foreach ($context['knowledge_hits'] as $i => $hit) {
                    $refId = 'K' . ($i + 1);
                    if ($refs->isNotEmpty() && ! $refs->contains($refId)) {
                        continue;
                    }
                    $citations[] = AiCitation::create([
                        'ai_message_id'       => $assistantMessage->id,
                        'knowledge_chunk_id'  => $hit['chunk']->id,
                        'knowledge_source_id' => $hit['chunk']->knowledge_source_id,
                        'source_type'         => 'knowledge',
                        'title'               => $hit['chunk']->source?->title ?? 'Untitled source',
                        'url'                 => $hit['chunk']->source?->source_url,
                        'snippet'             => mb_substr($hit['chunk']->content, 0, 300),
                        'score'               => $hit['score'],
                        'last_indexed_at'     => $hit['chunk']->source?->last_indexed_at,
                    ]);
                }
                foreach ($context['evidence'] as $type => $bundle) {
                    foreach (array_slice($bundle['evidence'], 0, 4) as $ev) {
                        $citations[] = AiCitation::create([
                            'ai_message_id' => $assistantMessage->id,
                            'source_type'   => (string) ($ev['source'] ?? $type),
                            'title'         => (string) ($ev['label'] ?? $type),
                            'snippet'       => (string) ($ev['value'] ?? ''),
                            'data_date'     => null,
                            'last_indexed_at' => null,
                        ]);
                    }
                }

                $answerJson = $result['answer_json'];
                if (is_array($answerJson)) {
                    $answerJson['date_range'] = $range
                        ? ['from' => $range->dateFrom(), 'to' => $range->dateTo()]
                        : null;
                    unset($answerJson['citation_refs']);
                }

                $assistantMessage->forceFill([
                    'content'     => $result['content'],
                    'answer_json' => $answerJson,
                    'status'      => 'completed',
                ])->save();

                AiRun::create([
                    'organization_id' => $org->id,
                    'ai_message_id'   => $assistantMessage->id,
                    'provider'        => $result['provider'],
                    'model'           => $result['model'],
                    'prompt_version'  => CopilotChat::PROMPT_VERSION,
                    'latency_ms'      => (int) ((microtime(true) - $startedAt) * 1000),
                    'tokens_in'       => $result['tokens_in'],
                    'tokens_out'      => $result['tokens_out'],
                    'status'          => 'completed',
                ]);

                $this->usage->recordAiMessage($org);

                $emit('citations', [
                    'citations' => collect($citations)->map(fn ($c) => $c->only([
                        'id', 'source_type', 'title', 'url', 'snippet', 'score', 'last_indexed_at',
                    ])),
                ]);
                $emit('run.completed', [
                    'message' => $assistantMessage->fresh()->only(['id', 'role', 'content', 'answer_json', 'status', 'created_at']),
                    'remaining_messages' => max(0, $this->usage->limit($org, 'monthly_ai_messages') - (int) $this->usage->counter($org)->ai_messages_used),
                ]);
            } catch (\Throwable $e) {
                report($e);

                $assistantMessage->forceFill([
                    'status'     => 'failed',
                    'error_code' => 'provider_error',
                    'content'    => '',
                ])->save();

                AiRun::create([
                    'organization_id' => $org->id,
                    'ai_message_id'   => $assistantMessage->id,
                    'provider'        => $this->chat->mode($org),
                    'model'           => 'unknown',
                    'prompt_version'  => CopilotChat::PROMPT_VERSION,
                    'latency_ms'      => (int) ((microtime(true) - $startedAt) * 1000),
                    'status'          => 'failed',
                    'error_message'   => mb_substr($e->getMessage(), 0, 500),
                ]);

                // No usage charged on failure.
                $emit('run.failed', [
                    'message_id' => $assistantMessage->id,
                    'code'       => 'provider_error',
                    'message'    => 'The AI provider is unavailable right now. No usage was charged.',
                ]);
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }

    public function feedback(Request $request, int $messageId)
    {
        // Org scope via global scope + ownership of the *thread* the
        // message belongs to — matches findThread() below. A message's own
        // user_id only identifies the author of 'user'-role rows (it's null
        // for 'assistant' replies), so it can't be used as the ownership
        // check here; without this, any org member could rate/comment on
        // another member's private Copilot thread by guessing/incrementing
        // this numeric id.
        $message = AiMessage::whereHas('thread', fn ($q) => $q->where('user_id', $request->user()->id))
            ->find($messageId);
        if (! $message) {
            return response()->json(['message' => 'Message not found.'], 404);
        }

        $data = $request->validate([
            'rating'  => ['required', Rule::in([1, -1])],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $feedback = AiFeedback::updateOrCreate(
            ['ai_message_id' => $message->id, 'user_id' => $request->user()->id],
            ['rating' => $data['rating'], 'comment' => $data['comment'] ?? null],
        );

        return response()->json(['feedback' => $feedback->only(['id', 'rating', 'comment'])]);
    }

    /** Suggested questions — static starters filtered by what has data. */
    public function suggestions(Request $request)
    {
        return response()->json(['suggestions' => [
            'Why did revenue change this week?',
            'Which SEO queries have high impressions but low clicks?',
            'Which products are losing sales momentum?',
            'What should this business prioritize next?',
            'Summarize this month vs last month.',
            'What do my uploaded documents say about our refund policy?',
        ]]);
    }

    private function findThread(Request $request, int $id): ?AiThread
    {
        // Org scope via global scope + personal ownership.
        return AiThread::where('user_id', $request->user()->id)->find($id);
    }
}
