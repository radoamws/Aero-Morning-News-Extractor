export type NewsItem = {
  id: number;
  lang: "FR" | "EN";
  title: string;
  content: string;
  metadescription: string;
  focuskeyphrase: string;
  categories: string | null;
  tags: string | null;
  image_url: string | null;
  status: 0 | 1 | 2;
  email_message_id: string | null;
  created_at: string;
  updated_at: string;
};

export type PaginatedNews = {
  data: NewsItem[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
};

export type IgnoredEmailItem = {
  id: number;
  message_id: string | null;
  subject: string | null;
  sender: string | null;
  reason: string;
  excerpt: string | null;
  processed_at: string | null;
  force_published_at: string | null;
  created_at: string;
};

export type NewsFilters = {
  q: string;
  status: "" | "0" | "1" | "2";
  lang: "" | "FR" | "EN";
  sort_by: "created_at" | "updated_at" | "title" | "status" | "id";
  sort_dir: "asc" | "desc";
  page: number;
  per_page: number;
};
