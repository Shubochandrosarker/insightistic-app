<?php

/*
| Copilot agent registry. Each workspace agent narrows Copilot to one job:
| a role/system prompt, the evidence sources it may read, the shared-brain
| (RAG) agent + skill category it maps to, and starter prompts. Sources and
| skills are disjoint across agents so no two agents cover the same task.
| RAG slugs default to the configured shared agent and can be overridden
| per agent via env.
*/

$rag = fn (string $key) => env('COPILOT_AGENT_'.strtoupper(str_replace('-', '_', $key)).'_RAG_SLUG');

return [
    'seo' => [
        'name' => 'SEO Analyst',
        'role' => 'You are the SEO Analyst. Turn on-page signals into a prioritized SEO fix list (titles, metas, headings, schema, internal structure). Do not analyse search-console trends, keyword research, crawl errors or traffic channels; point the user to the matching agent instead.',
        'sources' => ['seo'],
        'ai_category' => 'seo-onpage',
        'rag_agent_slug' => $rag('seo'),
        'skills' => ['on-page-analysis', 'technical-seo'],
        'starters' => ['Find the highest-impact SEO fixes for this site.', 'Which pages have weak titles or meta descriptions?'],
    ],
    'search' => [
        'name' => 'Search Analyst',
        'role' => 'You are the Search Analyst. Explain Google Search Console performance: clicks, impressions, CTR and position movement, and CTR opportunities. Do not do keyword research or on-page audits.',
        'sources' => ['executive', 'alerts'],
        'ai_category' => 'search-performance',
        'rag_agent_slug' => $rag('search'),
        'skills' => ['gsc-insights', 'ctr-opportunities', 'rank-movement'],
        'starters' => ['What changed in search performance this month?', 'Which queries lost the most clicks?'],
    ],
    'keyword' => [
        'name' => 'Keyword Analyst',
        'role' => 'You are the Keyword Analyst. Find keyword gaps, cluster queries by intent and outline content briefs for new topics. Do not review existing articles or search trends.',
        'sources' => ['report'],
        'ai_category' => 'keyword-research',
        'rag_agent_slug' => $rag('keyword'),
        'skills' => ['keyword-gaps', 'intent-mapping'],
        'starters' => ['Build a keyword cluster from our recent search queries.', 'Which high-intent topics are we missing?'],
    ],
    'article' => [
        'name' => 'Article Analyst',
        'role' => 'You are the Article Analyst. Evaluate existing articles: decay, engagement, internal links and refresh priorities. Do not research new keywords or crawl errors.',
        'sources' => ['content'],
        'ai_category' => 'content-analysis',
        'rag_agent_slug' => $rag('article'),
        'skills' => ['article-analysis', 'internal-links', 'content-refresh'],
        'starters' => ['Which articles should we improve first and why?', 'Which posts are decaying in traffic?'],
    ],
    'site-audit' => [
        'name' => 'Site Audit Analyst',
        'role' => 'You are the Site Audit Analyst. Summarize crawl and Core Web Vitals issues (broken links, errors, speed) and rank them by severity. Do not give on-page copy advice.',
        'sources' => ['site_audit', 'pagespeed', 'performance'],
        'ai_category' => 'site-audit',
        'rag_agent_slug' => $rag('site-audit'),
        'skills' => ['crawl-issues', 'broken-links', 'health-scoring'],
        'starters' => ['Summarize the critical issues from our latest crawl.', 'What is slowing our pages down?'],
    ],
    'growth' => [
        'name' => 'Growth Analyst',
        'role' => 'You are the Growth Analyst. Analyse GA4 traffic, channel mix, conversions and revenue to find growth opportunities. Do not do SEO audits.',
        'sources' => ['traffic', 'commerce', 'products', 'customers'],
        'ai_category' => 'growth-analytics',
        'rag_agent_slug' => $rag('growth'),
        'skills' => ['ga4-analysis', 'channel-mix', 'revenue-signals'],
        'starters' => ['Where is the clearest growth opportunity in our data?', 'Which channel grew or dropped the most?'],
    ],
    'knowledge' => [
        'name' => 'Knowledge Curator',
        'role' => 'You are the Knowledge Curator. Review the knowledge bases: coverage gaps, stale or conflicting sources and citation quality. Answer only from knowledge sources, not site analytics.',
        'sources' => [],
        'ai_category' => 'knowledge-curation',
        'rag_agent_slug' => $rag('knowledge'),
        'skills' => ['source-review', 'knowledge-gaps', 'citations'],
        'starters' => ['Show me the knowledge gaps that could weaken AI answers.', 'Which sources look outdated?'],
    ],
];
