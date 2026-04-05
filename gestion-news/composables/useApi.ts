import { useRuntimeConfig } from "nuxt/app";

export const useApi = () => {
  const config = useRuntimeConfig();

  const request = async <T>(path: string, options?: Parameters<typeof $fetch<T>>[1]) => {
    const headers: Record<string, string> = {
      Accept: "application/json",
      ...(options?.headers as Record<string, string> | undefined)
    };

    if (typeof window !== "undefined") {
      const token = localStorage.getItem("gestion-news-token");
      if (token) {
        headers.Authorization = `Bearer ${token}`;
      }
    }

    return await $fetch<T>(`${config.public.apiBaseUrl}${path}`, {
      ...options,
      headers
    });
  };

  return { request };
};
