import { defineStore } from "pinia";
import { useApi } from "~/composables/useApi";

type SessionUser = {
  id: number;
  identifier: string;
  email: string;
  loggedAt: string;
};

const SESSION_KEY = "gestion-news-session";

export const useAuthStore = defineStore("auth", {
  state: () => ({
    isAuthenticated: false,
    isHydrated: false,
    user: null as SessionUser | null
  }),
  actions: {
    hydrate() {
      if (typeof window === "undefined") {
        return;
      }

      const raw = localStorage.getItem(SESSION_KEY);
      if (!raw) {
        this.isHydrated = true;
        return;
      }

      try {
        const parsed = JSON.parse(raw) as SessionUser;
        this.user = parsed;
        this.isAuthenticated = true;
      } catch {
        localStorage.removeItem(SESSION_KEY);
      } finally {
        this.isHydrated = true;
      }
    },
    async login(identifier: string, password: string) {
      const { request } = useApi();

      try {
        const response = await request<{
          success: boolean;
          data: { token: string; user: { id: number; name: string; email: string } };
        }>("/auth/login", {
          method: "POST",
          body: { identifier, password }
        });

        const session: SessionUser = {
          id: response.data.user.id,
          identifier: response.data.user.name,
          email: response.data.user.email,
          loggedAt: new Date().toISOString()
        };

        this.user = session;
        this.isAuthenticated = true;
        this.isHydrated = true;

        if (typeof window !== "undefined") {
          localStorage.setItem(SESSION_KEY, JSON.stringify(session));
          localStorage.setItem("gestion-news-token", response.data.token);
        }

        return true;
      } catch {
        return false;
      }
    },
    async checkAuth() {
      const { request } = useApi();

      if (typeof window === "undefined") {
        return false;
      }

      const token = localStorage.getItem("gestion-news-token");
      if (!token) {
        this.logoutLocal();
        return false;
      }

      try {
        await request<{ success: boolean }>("/auth/me", { method: "GET" });
        return true;
      } catch {
        this.logoutLocal();
        return false;
      }
    },
    logoutLocal() {
      this.user = null;
      this.isAuthenticated = false;
      this.isHydrated = true;

      if (typeof window !== "undefined") {
        localStorage.removeItem(SESSION_KEY);
        localStorage.removeItem("gestion-news-token");
      }
    },
    async logout() {
      const { request } = useApi();

      try {
        await request<{ success: boolean }>("/auth/logout", { method: "POST" });
      } catch {
        // no-op
      } finally {
        this.logoutLocal();
      }
    }
  }
});
