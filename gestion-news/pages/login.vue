<script setup lang="ts">
import { ref } from "vue";
import { useRouter } from "vue-router";
import { useAuthStore } from "~/stores/auth";

const auth = useAuthStore();
const router = useRouter();

const identifier = ref("");
const password = ref("");
const error = ref("");

const handleLogin = async () => {
  error.value = "";

  const ok = await auth.login(identifier.value.trim(), password.value);
  if (!ok) {
    error.value = "Identifiant ou mot de passe invalide";
    return;
  }

  router.push("/dashboard");
};
</script>

<template>
  <section>
    <p class="eyebrow">Administration</p>
    <h1 class="auth-title">Gestion et verification des news</h1>
    <p class="muted">Connectez-vous pour piloter les traitements et statuts.</p>

    <form class="form-grid" @submit.prevent="handleLogin">
      <label>
        <span>Identifiant</span>
        <input v-model="identifier" type="text" required autocomplete="username" />
      </label>

      <label>
        <span>Mot de passe</span>
        <input v-model="password" type="password" required autocomplete="current-password" />
      </label>

      <button class="btn btn-primary" type="submit">Se connecter</button>
    </form>

    <p v-if="error" class="error">{{ error }}</p>
  </section>
</template>
