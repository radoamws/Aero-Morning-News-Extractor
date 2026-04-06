import { defineStore } from "pinia";
import type { NewsFilters, NewsItem, PaginatedNews } from "~/types/news";
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
    preview: ""
  }),
  getters: {
    allSelected(state) {
      return state.rows.length > 0 && state.rows.every((row) => state.selectedIds.includes(row.id));
    },
    selectedCount(state) {
      return state.selectedIds.length;
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
      try {
        const result = await request<{ message: string; processed?: number; failed?: number }>("/process-emails", {
          method: "POST"
        });

        // Automatically chain publishing so freshly created status=0 rows are sent to WordPress.
        const publishResult = await request<{ message: string; published?: number; failed?: number }>("/publish-pending", {
          method: "POST"
        });

        const details =
          ` (emails processed: ${result.processed ?? 0}, emails failed: ${result.failed ?? 0}, ` +
          `published: ${publishResult.published ?? 0}, publish failed: ${publishResult.failed ?? 0})`;

        this.setMessage((publishResult.message || "Traitement + publication termines") + details);
        await Promise.all([this.fetchNews(), this.fetchStats()]);
      } catch (error: any) {
        this.setError(error?.data?.message || "Echec traitement emails / publication");
      } finally {
        this.actionLoading = false;
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
    async fetchPreview(id: number) {
      const { request } = useApi();
      this.actionLoading = true;
      this.preview = "";

      try {
        const result = await request<{ data: { content: string } }>(`/news/${id}/preview`);
        this.preview = result.data.content;
        this.setMessage(`Preview chargee pour la news ${id}`);
      } catch (error: any) {
        this.setError(error?.data?.message || "Echec chargement preview");
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
