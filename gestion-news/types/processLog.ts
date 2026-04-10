export type ProcessLogStatus = "running" | "success" | "partial" | "failed";

export type ProcessLogItem = {
  id: number;
  process_type: string;
  status: ProcessLogStatus;
  source: string | null;
  news_id: number | null;
  email_message_id: string | null;
  message: string | null;
  details: string | null;
  started_at: string | null;
  finished_at: string | null;
  created_at: string;
  updated_at: string;
};

export type PaginatedProcessLogs = {
  data: ProcessLogItem[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
};
