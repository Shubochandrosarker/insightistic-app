/** Shared types + helpers for the AI Knowledge Base pages (and Copilot's KB picker). */

export interface UsageEntry {
  used: number;
  limit: number | null;
}

export interface KnowledgeUsage {
  knowledge_bases?: UsageEntry;
  knowledge_sources?: UsageEntry;
  storage_mb?: UsageEntry;
  indexed_chunks?: UsageEntry;
  monthly_embeddings?: UsageEntry;
  monthly_ai_messages?: UsageEntry;
}

export interface KnowledgeBase {
  id: number;
  name: string;
  description?: string | null;
  scope?: string | null;
  status: string;
  site_id?: number | null;
  sources_count: number;
  indexed_sources_count: number;
  created_at: string;
}

export type SourceStatus = "pending" | "processing" | "indexed" | "failed" | "disabled";

export interface KnowledgeSource {
  id: number;
  type: string; // file | url | sitemap | manual
  title: string;
  source_url?: string | null;
  mime_type?: string | null;
  size_bytes?: number | null;
  status: SourceStatus;
  error_code?: string | null;
  error_message?: string | null;
  chunk_count: number;
  last_indexed_at?: string | null;
  created_at: string;
  ingestion_jobs?: any[];
}

export interface RetrievalResult {
  chunk_id: number | string;
  score: number;
  rank_sources?: string[] | string | null;
  content: string;
  heading?: string | null;
  source: { id: number; title: string; type: string; url?: string | null; last_indexed_at?: string | null };
}

export const SOURCE_STATUS_TONE: Record<SourceStatus, "green" | "amber" | "red" | "violet" | "slate"> = {
  pending: "slate",
  processing: "amber",
  indexed: "green",
  failed: "red",
  disabled: "slate",
};

export function formatBytes(bytes?: number | null): string {
  if (bytes == null) return "—";
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
