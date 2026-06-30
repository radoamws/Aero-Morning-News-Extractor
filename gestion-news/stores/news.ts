import { defineStore } from "pinia";
import type { IgnoredEmailItem, NewsFilters, NewsItem, PaginatedNews } from "~/types/news";
import type { PaginatedProcessLogs, ProcessLogItem } from "~/types/processLog";
import { useApi } from "~/composables/useApi";

type ApiResult<T> = {
  success: boolean;
  message?: string;
  data: T;
};

export const useNewsStore = defineStore("news", {
  state: () => ({
    rows: [] as NewsItem[],
    selectedIds: [] as number[],
    loading: false,
    actionLoading: false,
    isProcessing: false,
    processProgress: {
      current: 0,
      total: 0,
      stage: "idle", // "emails" | "publishing" | "idle"
      message: ""
    },
    message: "",
    error: "",
    pagination: {
      current_page: 1,
      last_page: 1,
      per_page: 20,
      total: 0
    },
    filters: {
      q: "",
      status: "",
      lang: "",
      sort_by: "created_at",
      sort_dir: "desc",
      page: 1,
      per_page: 20
    } as NewsFilters,
    stats: null as null | Record<string, any>,
    preview: "",
    previewItem: null as null | NewsItem,

    processLogs: [] as ProcessLogItem[],
    processLogsLoading: false,
    seoRepushStatus: null as null | Record<string, any>,
    yoastReindexStatus: null as null | Record<string, any>,

    ignoredEmails: [] as IgnoredEmailItem[],
    ignoredEmailsLoading: false,
    ignoredEmailsPagination: {
      current_page: 1,
      last_page: 1,
      per_page: 20,
      total: 0
    },
    ignoredEmailsFilters: {
      q: "",
      force_published: "" as "" | "0" | "1"
    },

    processLogsPagination: {
      current_page: 1,
      last_page: 1,
      per_page: 20,
      total: 0
    }
  }),
  getters: {
    allSelected(state) {
      return state.rows.length > 0 && state.rows.every((row) => state.selectedIds.includes(row.id));
    },
    selectedCount(state) {
      return state.selectedIds.length;
    },
    previewSeoWarnings(state) {
      if (!state.previewItem) return [];
      const warnings: string[] = [];
      
      if (state.previewItem.title.length === 0) {
        warnings.push("Title is empty");
      } else if (state.previewItem.title.length > 62) {
        warnings.push(`Title too long: ${state.previewItem.title.length}/62 chars`);
      }
      
      if (state.previewItem.metadescription.length === 0) {
        warnings.push("Meta description is empty");
      } else if (state.previewItem.metadescription.length < 107) {
        warnings.push(`Meta description too short: ${state.previewItem.metadescription.length}/107-142 chars`);
      } else if (state.previewItem.metadescription.length > 142) {
        warnings.push(`Meta description too long: ${state.previewItem.metadescription.length}/107-142 chars`);
      }
      
      if (state.previewItem.focuskeyphrase.length === 0) {
        warnings.push("Focus keyphrase is empty");
      } else {
        const words = state.previewItem.focuskeyphrase.trim().split(/\s+/).filter(w => w.length > 0);
        if (words.length < 2 || words.length > 5) {
          warnings.push(`Focus keyphrase word count: ${words.length} (must be 2-5 words)`);
        }
      }
      
      return warnings;
    }
  },
  actions: {
    setMessage(text: string) {
      this.message = text;
      this.error = "";
    },
    setError(text: string) {
      this.error = text;
      this.message = "";
    },
    toggleSelection(id: number) {
      if (this.selectedIds.includes(id)) {
        this.selectedIds = this.selectedIds.filter((x) => x !== id);
        return;
      }
      this.selectedIds.push(id);
    },
    toggleAllCurrentPage() {
      if (this.allSelected) {
        this.selectedIds = this.selectedIds.filter((id) => !this.rows.some((row) => row.id === id));
        return;
      }

      const currentIds = this.rows.map((row) => row.id);
      this.selectedIds = Array.from(new Set([...this.selectedIds, ...currentIds]));
    },
    clearSelection() {
      this.selectedIds = [];
    },
    async fetchNews() {
      const { request } = useApi();
      this.loading = true;
      this.error = "";

      try {
        const params = new URLSearchParams();
        Object.entries(this.filters).forEach(([key, value]) => {
          if (value !== "" && value !== null && value !== undefined) {
            params.set(key, String(value));
          }
        });

        const response = await request<ApiResult<PaginatedNews>>(`/news?${params.toString()}`);
        this.rows = response.data.data;
        this.pagination = {
          current_page: response.data.current_page,
          last_page: response.data.last_page,
          per_page: response.data.per_page,
          total: response.data.total
        };
      } catch (error: any) {
        this.setError(error?.data?.message || "Impossible de charger la liste des news");
      } finally {
        this.loading = false;
      }
    },
    async fetchStats() {
      const { request } = useApi();
      try {
        const response = await request<ApiResult<Record<string, any>>>("/stats");
        this.stats = response.data;
      } catch {
        this.stats = null;
      }
    },

    async fetchProcessLogs() {
      const { request } = useApi();
      this.processLogsLoading = true;

      try {
        const params = new URLSearchParams();
        params.set("per_page", "20");
        params.set("sort_by", "created_at");
        params.set("sort_dir", "desc");

        const response = await request<{ success: boolean; data: PaginatedProcessLogs }>(
          `/process-logs?${params.toString()}`
        );

        this.processLogs = response.data.data;
        this.processLogsPagination = {
          current_page: response.data.current_page,
          last_page: response.data.last_page,
          per_page: response.data.per_page,
          total: response.data.total
        };
      } catch {
        this.processLogs = [];
        this.processLogsPagination = {
          current_page: 1,
          last_page: 1,
          per_page: 20,
          total: 0
        };
      } finally {
        this.processLogsLoading = false;
      }
    },
    async fetchSeoRepushStatus() {
      const { request } = useApi();

      try {
        const response = await request<{ success: boolean; data: Record<string, any> }>("/repush-seo-meta/status");
        this.seoRepushStatus = response.data;
      } catch {
        this.seoRepushStatus = null;
      }
    },
    async fetchYoastReindexStatus() {
      const { request } = useApi();

      try {
        const response = await request<{ success: boolean; data: Record<string, any> }>("/reindex-yoast-scores/status");
        this.yoastReindexStatus = response.data;
      } catch {
        this.yoastReindexStatus = null;
      }
    },
    async runSyncWordPress() {
      const { request } = useApi();
      this.actionLoading = true;
      try {
        const result = await request<{ success: boolean; message: string }>("/sync-wordpress", {
          method: "POST"
        });
        this.setMessage(result.message || "Synchronisation WordPress terminee");
      } catch (error: any) {
        this.setError(error?.data?.message || "Echec synchronisation WordPress");
      } finally {
        this.actionLoading = false;
      }
    },
    async runProcessEmails() {
      const { request } = useApi();
      this.actionLoading = true;
      this.isProcessing = true;
      this.processProgress = {
        current: 0,
        total: 0,
        stage: "emails",
        message: "Connecting to IMAP server..."
      };

      try {
        // Simulate progress while waiting
        let progressInterval = setInterval(() => {
          if (this.processProgress.current < 95) {
            this.processProgress.current += Math.random() * 15;
            if (this.processProgress.current > 95) this.processProgress.current = 95;
          }
        }, 300);

        const result = await request<{ message: string; processed?: number; failed?: number }>("/process-emails", {
          method: "POST"
        });
        clearInterval(progressInterval);

        this.processProgress.current = 100;
        this.processProgress.message = `Emails processed (${result.processed ?? 0})`;

        // Now publish
        this.processProgress.stage = "publishing";
        this.processProgress.current = 0;
        this.processProgress.message = "Publishing to WordPress...";

        progressInterval = setInterval(() => {
          if (this.processProgress.current < 95) {
            this.processProgress.current += Math.random() * 15;
            if (this.processProgress.current > 95) this.processProgress.current = 95;
          }
        }, 300);

        const publishResult = await request<{ message: string; published?: number; failed?: number }>("/publish-pending", {
          method: "POST"
        });
        clearInterval(progressInterval);

        this.processProgress.current = 100;
        const details =
          ` (emails processed: ${result.processed ?? 0}, emails failed: ${result.failed ?? 0}, ` +
          `published: ${publishResult.published ?? 0}, publish failed: ${publishResult.failed ?? 0})`;

        this.setMessage((publishResult.message || "Processing + publishing complete") + details);
        await Promise.all([this.fetchNews(), this.fetchStats()]);
      } catch (error: any) {
        this.setError(error?.data?.message || "Email processing or publishing failed");
      } finally {
        setTimeout(() => {
          this.actionLoading = false;
          this.isProcessing = false;
          this.processProgress = {
            current: 0,
            total: 0,
            stage: "idle",
            message: ""
          };
        }, 1000);
      }
    },
    async runPublishPending() {
      const { request } = useApi();
      this.actionLoading = true;
      try {
        const result = await request<{ message: string; published?: number; failed?: number }>("/publish-pending", {
          method: "POST"
        });
        const details = ` (published: ${result.published ?? 0}, failed: ${result.failed ?? 0})`;
        this.setMessage((result.message || "Publication WordPress terminee") + details);
        await Promise.all([this.fetchNews(), this.fetchStats()]);
      } catch (error: any) {
        this.setError(error?.data?.message || "Echec publication WordPress");
      } finally {
        this.actionLoading = false;
      }
    },
    async runPurgeCloudflare() {
      const { request } = useApi();
      this.actionLoading = true;
      try {
        const result = await request<{ message: string; urls?: string[]; skipped?: boolean }>(
          "/cloudflare/purge-homepage",
          { method: "POST" }
        );
        const urlsText = result.urls?.length ? ` (${result.urls.join(", ")})` : "";
        this.setMessage((result.message || "Cache Cloudflare purgé") + urlsText);
      } catch (error: any) {
        this.setError(error?.data?.message || "Echec purge cache Cloudflare");
      } finally {
        this.actionLoading = false;
      }
    },
    async runRepushSeoMeta(limit = 200, resumeAfterNewsId?: number | null) {
      const { request } = useApi();
      this.actionLoading = true;

      try {
        const body: Record<string, number> = { limit };
        if (typeof resumeAfterNewsId === "number" && Number.isFinite(resumeAfterNewsId) && resumeAfterNewsId > 0) {
          body.resume_after_news_id = resumeAfterNewsId;
        }

        const result = await request<{
          message: string;
          data?: {
            processed?: number;
            updated?: number;
            not_found?: number;
            failed?: number;
            next_resume_after_news_id?: number | null;
            has_more?: boolean;
          };
        }>("/repush-seo-meta", {
          method: "POST",
          body
        });

        const details = result.data || {};
        const checkpointText = details.next_resume_after_news_id
          ? `, reprise apres news ID ${details.next_resume_after_news_id}`
          : "";

        this.setMessage(
          `${result.message || "Repush SEO termine"} ` +
            `(processed: ${details.processed ?? 0}, updated: ${details.updated ?? 0}, not found: ${details.not_found ?? 0}, failed: ${details.failed ?? 0}${checkpointText})`
        );

        await Promise.all([this.fetchNews(), this.fetchStats(), this.fetchProcessLogs(), this.fetchSeoRepushStatus()]);
      } catch (error: any) {
        this.setError(error?.data?.message || "Echec repush SEO");
      } finally {
        this.actionLoading = false;
      }
    },
    async runYoastReindexScores(limit = 200, resumeAfterNewsId?: number | null) {
      const { request } = useApi();
      this.actionLoading = true;

      try {
        const body: Record<string, number> = { limit };
        if (typeof resumeAfterNewsId === "number" && Number.isFinite(resumeAfterNewsId) && resumeAfterNewsId > 0) {
          body.resume_after_news_id = resumeAfterNewsId;
        }

        const result = await request<{
          message: string;
          data?: {
            processed?: number;
            updated?: number;
            not_found?: number;
            failed?: number;
            next_resume_after_news_id?: number | null;
          };
        }>("/reindex-yoast-scores", {
          method: "POST",
          body
        });

        const details = result.data || {};
        const checkpointText = details.next_resume_after_news_id
          ? `, reprise apres news ID ${details.next_resume_after_news_id}`
          : "";

        this.setMessage(
          `${result.message || "Reindex Yoast termine"} ` +
            `(processed: ${details.processed ?? 0}, updated: ${details.updated ?? 0}, not found: ${details.not_found ?? 0}, failed: ${details.failed ?? 0}${checkpointText})`
        );

        await Promise.all([
          this.fetchNews(),
          this.fetchStats(),
          this.fetchProcessLogs(),
          this.fetchSeoRepushStatus(),
          this.fetchYoastReindexStatus()
        ]);
      } catch (error: any) {
        this.setError(error?.data?.message || "Echec reindex Yoast");
      } finally {
        this.actionLoading = false;
      }
    },
    async updateSingleStatus(id: number, status: 0 | 1 | 2) {
      const { request } = useApi();
      this.actionLoading = true;
      try {
        const result = await request<{ message: string }>(`/news/${id}/status/${status}`, {
          method: "PATCH"
        });
        this.setMessage(result.message || `Statut de la news ${id} mis a jour`);
        await this.fetchNews();
      } catch (error: any) {
        this.setError(error?.data?.message || "Echec mise a jour du statut");
      } finally {
        this.actionLoading = false;
      }
    },
    async updateBulkStatus(status: 0 | 1 | 2, mode: "selected" | "filtered" | "all") {
      const { request } = useApi();
      this.actionLoading = true;
      try {
        const payload: Record<string, any> = {};

        if (mode === "selected") {
          payload.ids = this.selectedIds;
        } else if (mode === "filtered") {
          if (this.filters.status !== "") {
            payload.status_filter = Number(this.filters.status);
          }
          if (this.filters.lang !== "") {
            payload.lang = this.filters.lang;
          }
        }

        const result = await request<{ message: string; updated_count: number }>(`/news/bulk/status/${status}`, {
          method: "PATCH",
          body: payload
        });

        this.setMessage(`${result.message || "Mise a jour terminee"} - ${result.updated_count} lignes`);
        await this.fetchNews();
      } catch (error: any) {
        this.setError(error?.data?.message || "Echec mise a jour en masse");
      } finally {
        this.actionLoading = false;
      }
    },
    async postSingleToWordPress(id: number, username: string, password: string) {
      const { request } = useApi();
      this.actionLoading = true;
      try {
        const result = await request<{ message: string }>(`/news/${id}/post-to-wordpress`, {
          method: "POST",
          body: { username, password }
        });
        this.setMessage(result.message || `News ${id} publiee`);
        await this.fetchNews();
      } catch (error: any) {
        this.setError(error?.data?.message || "Echec publication WordPress");
      } finally {
        this.actionLoading = false;
      }
    },
    async postBulkToWordPress(mode: "selected" | "filtered" | "all", username: string, password: string) {
      const { request } = useApi();
      this.actionLoading = true;
      try {
        let ids: number[] = [];

        if (mode === "selected") {
          ids = [...this.selectedIds];
        } else {
          ids = await this.collectIdsForCurrentFilter(mode === "all");
        }

        if (ids.length === 0) {
          this.setError("Aucune news cible a publier");
          return;
        }

        const result = await request<{ message: string; success_count: number; failed_count: number }>(
          "/news/bulk-post-to-wordpress",
          {
            method: "POST",
            body: {
              news_ids: ids,
              username,
              password
            }
          }
        );

        this.setMessage(
          `${result.message || "Publication groupee terminee"} - OK: ${result.success_count}, FAIL: ${result.failed_count}`
        );
        await this.fetchNews();
      } catch (error: any) {
        this.setError(error?.data?.message || "Echec publication groupee");
      } finally {
        this.actionLoading = false;
      }
    },
    async postToLinkedIn(id: number) {
      const { request } = useApi();
      this.actionLoading = true;
      try {
        const result = await request<{ message: string; url: string }>(`/news/${id}/post-to-linkedin`, {
          method: "POST",
        });
        this.setMessage(result.message || `News ${id} publiée sur LinkedIn`);
      } catch (error: any) {
        this.setError(error?.data?.message || "Échec publication LinkedIn");
      } finally {
        this.actionLoading = false;
      }
    },
    async fetchPreview(id: number) {
      const { request } = useApi();
      this.actionLoading = true;
      this.preview = "";
      this.previewItem = null;

      try {
        const item = this.rows.find(row => row.id === id);
        if (!item) {
          this.setError("News not found in current list");
          return;
        }
        
        const result = await request<{ data: { content: string } }>(`/news/${id}/preview`);
        this.preview = result.data.content;
        this.previewItem = item;
        this.setMessage(`Preview loaded for news ${id}`);
      } catch (error: any) {
        this.setError(error?.data?.message || "Failed to load preview");
      } finally {
        this.actionLoading = false;
      }
    },
    async fetchIgnoredEmails(page = 1) {
      const { request } = useApi();
      this.ignoredEmailsLoading = true;
      try {
        const params = new URLSearchParams();
        params.set("page", String(page));
        params.set("per_page", String(this.ignoredEmailsPagination.per_page));
        params.set("sort_dir", "desc");
        if (this.ignoredEmailsFilters.q) {
          params.set("q", this.ignoredEmailsFilters.q);
        }
        if (this.ignoredEmailsFilters.force_published !== "") {
          params.set("force_published", this.ignoredEmailsFilters.force_published);
        }

        const result = await request<{
          data: IgnoredEmailItem[];
          pagination: { current_page: number; last_page: number; per_page: number; total: number };
        }>(`/ignored-emails?${params.toString()}`);

        this.ignoredEmails = result.data;
        this.ignoredEmailsPagination = result.pagination;
      } catch (error: any) {
        this.setError(error?.data?.message || "Echec chargement mails ignorés");
      } finally {
        this.ignoredEmailsLoading = false;
      }
    },
    async forcePublishIgnoredEmail(id: number) {
      const { request } = useApi();
      this.actionLoading = true;
      try {
        const result = await request<{
          message: string;
          created_news: number[];
          publish_results: { success: any[]; failed: any[] };
        }>(`/ignored-emails/${id}/force-publish`, { method: "POST" });

        const ok  = result.publish_results?.success?.length ?? 0;
        const nok = result.publish_results?.failed?.length ?? 0;
        this.setMessage(`${result.message} (publiés: ${ok}, échecs: ${nok})`);
        await this.fetchIgnoredEmails(this.ignoredEmailsPagination.current_page);
      } catch (error: any) {
        this.setError(error?.data?.message || "Echec force publish");
      } finally {
        this.actionLoading = false;
      }
    },
    async collectIdsForCurrentFilter(ignoreFilters = false): Promise<number[]> {
      const { request } = useApi();
      const ids: number[] = [];
      let page = 1;
      let lastPage = 1;

      do {
        const params = new URLSearchParams();
        params.set("page", String(page));
        params.set("per_page", "100");
        params.set("sort_by", this.filters.sort_by);
        params.set("sort_dir", this.filters.sort_dir);

        if (!ignoreFilters) {
          if (this.filters.q) {
            params.set("q", this.filters.q);
          }
          if (this.filters.status) {
            params.set("status", this.filters.status);
          }
          if (this.filters.lang) {
            params.set("lang", this.filters.lang);
          }
        }

        const response = await request<{ data: PaginatedNews }>(`/news?${params.toString()}`);
        const pageData = response.data;
        ids.push(...pageData.data.map((item) => item.id));

        page = pageData.current_page + 1;
        lastPage = pageData.last_page;
      } while (page <= lastPage);

      return ids;
    }
  }
});
