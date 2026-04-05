export default defineNuxtConfig({
  ssr: false,
  modules: ["@pinia/nuxt"],
  css: ["~/assets/css/main.css"],
  runtimeConfig: {
    public: {
      apiBaseUrl: process.env.NUXT_PUBLIC_API_BASE_URL || "http://localhost/extract_news/api/public/api"
    }
  },
  app: {
    head: {
      title: "Gestion News",
      meta: [
        { name: "viewport", content: "width=device-width, initial-scale=1" },
        {
          name: "description",
          content: "Console de gestion des news Aeromorning: filtres, statuts, publication et actions manuelles"
        }
      ],
      link: [
        { rel: "preconnect", href: "https://fonts.googleapis.com" },
        { rel: "preconnect", href: "https://fonts.gstatic.com", crossorigin: "" },
        {
          rel: "stylesheet",
          href: "https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&family=Sora:wght@400;600;700&display=swap"
        }
      ]
    }
  }
});
