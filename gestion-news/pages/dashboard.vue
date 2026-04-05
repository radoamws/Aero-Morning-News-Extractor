<script setup lang="ts">
import { storeToRefs } from "pinia";
import { computed, onMounted, ref } from "vue";
import { useNewsStore } from "~/stores/news";


const newsStore = useNewsStore();

const {
  rows,
  loading,
  actionLoading,
  message,
  error,
  filters,
  pagination,
  selectedCount,
  allSelected,
  stats,
  preview
} = storeToRefs(newsStore);

const wpUser = ref("");
const wpPassword = ref("");
const singleStatusTarget = ref<0 | 1 | 2>(0);
const bulkStatusTarget = ref<0 | 1 | 2>(0);
const bulkMode = ref<"selected" | "filtered" | "all">("selected");
const selectedSingleId = ref<number | null>(null);

const setPage = async (page: number) => {
  filters.value.page = page;
  await newsStore.fetchNews();
};

const applyFilters = async () => {
  filters.value.page = 1;
  await newsStore.fetchNews();
};

const clearFilters = async () => {
  filters.value.q = "";
  filters.value.status = "";
  filters.value.lang = "";
  filters.value.sort_by = "created_at";
  filters.value.sort_dir = "desc";
  filters.value.page = 1;
  await newsStore.fetchNews();
};

const setSingleId = (id: number) => {
  selectedSingleId.value = id;
};

const selectedOrFallbackId = computed(() => {
  if (selectedSingleId.value) {
    return selectedSingleId.value;
  }

  if (newsStore.selectedIds.length === 1) {
    return newsStore.selectedIds[0];
  }

  return null;
});

const runSingleStatus = async () => {
  const id = selectedOrFallbackId.value;
  if (!id) {
    newsStore.setError("Selectionnez une seule news");
    return;
  }

  await newsStore.updateSingleStatus(id, singleStatusTarget.value);
};

const runSinglePreview = async () => {
  const id = selectedOrFallbackId.value;
  if (!id) {
    newsStore.setError("Selectionnez une seule news");
    return;
  }

  await newsStore.fetchPreview(id);
};

const runSinglePost = async () => {
  const id = selectedOrFallbackId.value;
  if (!id) {
    newsStore.setError("Selectionnez une seule news");
    return;
  }

  if (!wpUser.value || !wpPassword.value) {
    newsStore.setError("Renseignez identifiants WordPress");
    return;
  }

  await newsStore.postSingleToWordPress(id, wpUser.value, wpPassword.value);
};

const runBulkStatus = async () => {
  await newsStore.updateBulkStatus(bulkStatusTarget.value, bulkMode.value);
};

const runBulkPost = async () => {
  if (!wpUser.value || !wpPassword.value) {
    newsStore.setError("Renseignez identifiants WordPress");
    return;
  }

  await newsStore.postBulkToWordPress(bulkMode.value, wpUser.value, wpPassword.value);
};

onMounted(async () => {
  await Promise.all([newsStore.fetchNews(), newsStore.fetchStats()]);
});
</script>

<template>
  <section class="grid">
    <article class="panel panel-glow">
      <h2>Actions API</h2>
      <p class="muted">Lancement manuel des endpoints globaux.</p>

      <div class="actions-row">
        <button class="btn btn-primary" :disabled="actionLoading" @click="newsStore.runSyncWordPress()">
          Sync WordPress
        </button>
        <button class="btn btn-primary" :disabled="actionLoading" @click="newsStore.runProcessEmails()">
          Process Emails
        </button>
        <button class="btn btn-ghost" :disabled="loading" @click="newsStore.fetchNews()">Rafraichir liste</button>
        <button class="btn btn-ghost" @click="newsStore.fetchStats()">Rafraichir stats</button>
      </div>
    </article>

    <article class="panel">
      <h2>Filtres et tri</h2>
      <div class="toolbar-grid">
        <label>
          <span>Recherche</span>
          <input v-model="filters.q" type="text" placeholder="Titre, meta, keyphrase" />
        </label>

        <label>
          <span>Statut</span>
          <select v-model="filters.status">
            <option value="">Tous</option>
            <option value="0">Pending</option>
            <option value="1">Syncing</option>
            <option value="2">Synced</option>
          </select>
        </label>

        <label>
          <span>Langue</span>
          <select v-model="filters.lang">
            <option value="">Toutes</option>
            <option value="FR">FR</option>
            <option value="EN">EN</option>
          </select>
        </label>

        <label>
          <span>Trier par</span>
          <select v-model="filters.sort_by">
            <option value="created_at">Date creation</option>
            <option value="updated_at">Date maj</option>
            <option value="title">Titre</option>
            <option value="status">Statut</option>
            <option value="id">ID</option>
          </select>
        </label>

        <label>
          <span>Direction</span>
          <select v-model="filters.sort_dir">
            <option value="desc">Desc</option>
            <option value="asc">Asc</option>
          </select>
        </label>

        <label>
          <span>Par page</span>
          <select v-model.number="filters.per_page">
            <option :value="20">20</option>
            <option :value="50">50</option>
            <option :value="100">100</option>
          </select>
        </label>
      </div>

      <div class="actions-row">
        <button class="btn btn-primary" :disabled="loading" @click="applyFilters">Appliquer</button>
        <button class="btn btn-ghost" :disabled="loading" @click="clearFilters">Reset</button>
      </div>
    </article>

    <article class="panel">
      <h2>Actions unitaires</h2>
      <p class="muted">Selection unique: clic sur ligne ou une seule checkbox.</p>

      <div class="actions-row wrap">
        <label>
          <span>Statut cible</span>
          <select v-model.number="singleStatusTarget">
            <option :value="0">Pending</option>
            <option :value="1">Syncing</option>
            <option :value="2">Synced</option>
          </select>
        </label>

        <button class="btn btn-primary" :disabled="actionLoading" @click="runSingleStatus">Update statut</button>
        <button class="btn btn-ghost" :disabled="actionLoading" @click="runSinglePreview">Preview</button>
      </div>

      <div class="toolbar-grid two-col">
        <label>
          <span>WP Username</span>
          <input v-model="wpUser" type="text" placeholder="admin" />
        </label>

        <label>
          <span>WP Password</span>
          <input v-model="wpPassword" type="password" placeholder="application password" />
        </label>
      </div>

      <div class="actions-row">
        <button class="btn btn-primary" :disabled="actionLoading" @click="runSinglePost">Post single WordPress</button>
      </div>
    </article>

    <article class="panel">
      <h2>Actions groupees</h2>
      <p class="muted">Mode selected, filtered ou all.</p>

      <div class="actions-row wrap">
        <label>
          <span>Mode</span>
          <select v-model="bulkMode">
            <option value="selected">Selected</option>
            <option value="filtered">Filtered</option>
            <option value="all">All</option>
          </select>
        </label>

        <label>
          <span>Statut cible</span>
          <select v-model.number="bulkStatusTarget">
            <option :value="0">Pending</option>
            <option :value="1">Syncing</option>
            <option :value="2">Synced</option>
          </select>
        </label>

        <button class="btn btn-primary" :disabled="actionLoading" @click="runBulkStatus">Update statut en masse</button>
        <button class="btn btn-primary" :disabled="actionLoading" @click="runBulkPost">Post WordPress en masse</button>
      </div>

      <p class="muted">Selection courante: {{ selectedCount }} news.</p>
    </article>

    <article class="panel">
      <h2>Liste des news</h2>
      <div class="actions-row between">
        <p class="muted">
          Total: {{ pagination.total }} | Page {{ pagination.current_page }} / {{ pagination.last_page }}
        </p>
        <button class="btn btn-ghost" @click="newsStore.toggleAllCurrentPage()">
          {{ allSelected ? "Deselectionner la page" : "Selectionner la page" }}
        </button>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>ID</th>
              <th>Lang</th>
              <th>Titre</th>
              <th>Status</th>
              <th>Cree le</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="7">Chargement...</td>
            </tr>
            <tr v-for="row in rows" :key="row.id" @click="setSingleId(row.id)">
              <td>
                <input
                  :checked="newsStore.selectedIds.includes(row.id)"
                  type="checkbox"
                  @change.stop="newsStore.toggleSelection(row.id)"
                />
              </td>
              <td>{{ row.id }}</td>
              <td>
                <span class="pill">{{ row.lang }}</span>
              </td>
              <td class="truncate" :title="row.title">{{ row.title }}</td>
              <td>
                <span :class="['pill', `status-${row.status}`]">{{ row.status }}</span>
              </td>
              <td>{{ new Date(row.created_at).toLocaleString("fr-FR") }}</td>
              <td class="actions-mini">
                <button class="btn btn-mini" @click.stop="setSingleId(row.id)">Select</button>
                <button class="btn btn-mini" @click.stop="newsStore.fetchPreview(row.id)">Preview</button>
                <button class="btn btn-mini" @click.stop="newsStore.updateSingleStatus(row.id, 2)">Set 2</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="actions-row between">
        <button class="btn btn-ghost" :disabled="pagination.current_page <= 1" @click="setPage(pagination.current_page - 1)">
          Page precedente
        </button>
        <button
          class="btn btn-ghost"
          :disabled="pagination.current_page >= pagination.last_page"
          @click="setPage(pagination.current_page + 1)"
        >
          Page suivante
        </button>
      </div>
    </article>

    <article class="panel" v-if="stats">
      <h2>Statistiques</h2>
      <div class="stats-grid">
        <div>
          <p class="muted">Total</p>
          <strong>{{ stats.total }}</strong>
        </div>
        <div>
          <p class="muted">Pending</p>
          <strong>{{ stats.by_status?.pending }}</strong>
        </div>
        <div>
          <p class="muted">Syncing</p>
          <strong>{{ stats.by_status?.syncing }}</strong>
        </div>
        <div>
          <p class="muted">Synced</p>
          <strong>{{ stats.by_status?.synced }}</strong>
        </div>
      </div>
    </article>

    <article class="panel" v-if="preview">
      <h2>Preview HTML</h2>
      <div class="preview" v-html="preview" />
    </article>

    <article v-if="message" class="panel panel-success">{{ message }}</article>
    <article v-if="error" class="panel panel-error">{{ error }}</article>
  </section>
</template>
