import { defineNuxtRouteMiddleware, navigateTo } from "nuxt/app";
import { useAuthStore } from "~/stores/auth";

export default defineNuxtRouteMiddleware(async (to) => {
  const auth = useAuthStore();

  if (!auth.isHydrated) {
    auth.hydrate();
  }

  if (to.path === "/login") {
    if (auth.isAuthenticated) {
      return navigateTo("/dashboard");
    }
    return;
  }

  if (!auth.isAuthenticated) {
    return navigateTo("/login");
  }

  const ok = await auth.checkAuth();
  if (!ok) {
    return navigateTo("/login");
  }
});