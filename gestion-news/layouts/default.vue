<template>
  <div class="app-shell">
    <header class="topbar">
      <div>
        <p class="eyebrow">Aeromorning</p>
        <h1>Gestion des News</h1>
      </div>
      <div class="topbar-actions">
        <NuxtLink to="/dashboard" class="btn btn-ghost">Dashboard</NuxtLink>
        <button class="btn btn-danger" @click="logout">Deconnexion</button>
      </div>
    </header>
    <main class="container">
      <slot />
    </main>
  </div>
</template>

<script setup lang="ts">
import { useRouter } from "vue-router";
import { useAuthStore } from "~/stores/auth";

const auth = useAuthStore();
const router = useRouter();

const logout = () => {
  auth.logout();
  router.push("/login");
};
</script>
