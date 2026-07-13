<script setup lang="ts">
import { storeToRefs } from "pinia";
import { computed, onMounted, ref, watch } from "vue";
import { useNewsStore } from "~/stores/news";
import { useApi } from "~/composables/useApi";


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
  preview,
  previewItem,
  isProcessing,
  processProgress,
  processLogs,
  processLogsLoading,
  seoRepushStatus,
  yoastReindexStatus,
  ignoredEmails,
  ignoredEmailsLoading,
  ignoredEmailsPagination,
  ignoredEmailsFilters
} = storeToRefs(newsStore);

const wpUser = ref("");
const wpPassword = ref("");
const singleStatusTarget = ref<0 | 1 | 2>(0);
const bulkStatusTarget = ref<0 | 1 | 2>(0);
const bulkMode = ref<"selected" | "filtered" | "all">("selected");
const selectedSingleId = ref<number | null>(null);
const seoRepushLimit = ref(200);
const seoRepushResumeAfterId = ref("");
const seoRepushResumeTouched = ref(false);

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

const runSeoRepush = async () => {
  const parsedResumeAfterId = Number.parseInt(seoRepushResumeAfterId.value, 10);
  await newsStore.runRepushSeoMeta(
    seoRepushLimit.value,
    Number.isFinite(parsedResumeAfterId) && parsedResumeAfterId > 0 ? parsedResumeAfterId : null
  );
};

const runYoastReindex = async () => {
  const parsedResumeAfterId = Number.parseInt(seoRepushResumeAfterId.value, 10);
  await newsStore.runYoastReindexScores(
    seoRepushLimit.value,
    Number.isFinite(parsedResumeAfterId) && parsedResumeAfterId > 0 ? parsedResumeAfterId : null
  );
};

const resetSeoRepushResume = () => {
  seoRepushResumeAfterId.value = "";
  seoRepushResumeTouched.value = false;
};

watch(
  () => seoRepushStatus.value?.checkpoint?.next_resume_after_news_id,
  (nextValue) => {
    if (seoRepushResumeTouched.value && seoRepushResumeAfterId.value.trim() !== "") {
      return;
    }

    seoRepushResumeAfterId.value = nextValue ? String(nextValue) : "";
    seoRepushResumeTouched.value = false;
  },
  { immediate: true }
);

const applyIgnoredFilters = async () => {
  await newsStore.fetchIgnoredEmails(1);
};

const clearIgnoredFilters = async () => {
  ignoredEmailsFilters.value.q = "";
  ignoredEmailsFilters.value.force_published = "";
  await newsStore.fetchIgnoredEmails(1);
};

// ─── LinkedIn config ───────────────────────────────────────────────────────
const { request: apiRequest } = useApi();

const linkedinSettings = ref<{
  token_configured: boolean;
  token_expires_at: string | null;
  author_urn: string | null;
  author_name: string | null;
} | null>(null);
type LinkedInOrg = { urn: string; id: string; name: string };
const linkedinOrgs = ref<LinkedInOrg[]>([]);
const linkedinLoading = ref(false);
const linkedinMsg = ref("");
const linkedinErr = ref("");

const fetchLinkedinSettings = async () => {
  try {
    linkedinSettings.value = await apiRequest("/linkedin/settings");
  } catch (_) { /* not yet configured */ }
};

const openLinkedinAuth = async () => {
  linkedinLoading.value = true;
  linkedinMsg.value = "";
  linkedinErr.value = "";
  try {
    const result = await apiRequest<{ url: string }>("/linkedin/auth");
    window.open(result.url, "linkedin_oauth", "width=620,height=720,noopener");
    linkedinMsg.value = "Fenêtre LinkedIn ouverte. Autorisez l'application puis revenez ici et cliquez 'Vérifier le token'.";
  } catch (e: any) {
    linkedinErr.value = e?.data?.message || "Erreur lors de la génération de l'URL OAuth";
  } finally {
    linkedinLoading.value = false;
  }
};

const checkLinkedinToken = async () => {
  linkedinLoading.value = true;
  linkedinMsg.value = "";
  linkedinErr.value = "";
  try {
    linkedinSettings.value = await apiRequest("/linkedin/settings");
    if (linkedinSettings.value?.token_configured) {
      linkedinMsg.value = "Token détecté ! Passez à l'étape suivante.";
    } else {
      linkedinErr.value = "Token non encore enregistré. Autorisez l'application LinkedIn d'abord (Étape 1).";
    }
  } catch (e: any) {
    linkedinErr.value = e?.data?.message || "Erreur";
  } finally {
    linkedinLoading.value = false;
  }
};

const fetchLinkedinPages = async () => {
  linkedinLoading.value = true;
  linkedinMsg.value = "";
  linkedinErr.value = "";
  try {
    const result = await apiRequest<{ name: string; organizations: LinkedInOrg[] }>("/linkedin/auth-info");
    linkedinOrgs.value = result.organizations ?? [];
    if (linkedinOrgs.value.length === 0) {
      linkedinErr.value = `Aucune page administrée trouvée pour ${result.name}. Vérifiez que vous êtes bien admin de la page AeroMorning.`;
    } else {
      linkedinMsg.value = `${linkedinOrgs.value.length} page(s) trouvée(s). Sélectionnez AeroMorning.`;
    }
  } catch (e: any) {
    linkedinErr.value = e?.data?.message || "Erreur lors de la récupération des pages";
  } finally {
    linkedinLoading.value = false;
  }
};

const selectLinkedinOrg = async (org: LinkedInOrg) => {
  linkedinLoading.value = true;
  linkedinMsg.value = "";
  linkedinErr.value = "";
  try {
    await apiRequest("/linkedin/save-urn", {
      method: "POST",
      body: { urn: org.urn, name: org.name },
    });
    linkedinMsg.value = `✅ Page "${org.name}" sélectionnée. Les articles seront publiés en tant que ${org.name}.`;
    linkedinOrgs.value = [];
    await fetchLinkedinSettings();
  } catch (e: any) {
    linkedinErr.value = e?.data?.message || "Erreur lors de la sauvegarde";
  } finally {
    linkedinLoading.value = false;
  }
};
// ──────────────────────────────────────────────────────────────────────────

onMounted(async () => {
  await Promise.all([
    newsStore.fetchNews(),
    newsStore.fetchStats(),
    newsStore.fetchProcessLogs(),
    newsStore.fetchSeoRepushStatus(),
    newsStore.fetchYoastReindexStatus(),
    newsStore.fetchIgnoredEmails(1),
    fetchLinkedinSettings(),
  ]);
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
        <button class="btn btn-primary" :disabled="actionLoading" @click="newsStore.runPublishPending()">
          Publish Pending News
        </button>
        <button class="btn btn-ghost" :disabled="actionLoading" @click="newsStore.runPurgeCloudflare()">
          Purge Cache Cloudflare
        </button>
        <label>
          <span>Batch SEO</span>
          <input v-model.number="seoRepushLimit" type="number" min="1" max="1000" style="width: 120px;" />
        </label>
        <label>
          <span>Reprendre apres ID</span>
          <input
            v-model="seoRepushResumeAfterId"
            type="text"
            inputmode="numeric"
            placeholder="ex: 413"
            style="width: 140px;"
            @input="seoRepushResumeTouched = true"
          />
        </label>
        <button class="btn btn-ghost" type="button" :disabled="actionLoading" @click="resetSeoRepushResume">
          Reset
        </button>
        <button class="btn btn-primary" :disabled="actionLoading" @click="runSeoRepush">
          Repush SEO Meta
        </button>
        <button class="btn btn-primary" :disabled="actionLoading" @click="runYoastReindex">
          Reindex Yoast Scores
        </button>
        <button class="btn btn-ghost" :disabled="loading" @click="newsStore.fetchNews()">Rafraichir liste</button>
        <button class="btn btn-ghost" @click="newsStore.fetchStats()">Rafraichir stats</button>
        <button class="btn btn-ghost" :disabled="actionLoading" @click="newsStore.fetchSeoRepushStatus()">
          Rafraichir statut SEO
        </button>
        <button class="btn btn-ghost" :disabled="actionLoading" @click="newsStore.fetchYoastReindexStatus()">
          Rafraichir statut Reindex
        </button>
      </div>

      <div class="panel" style="margin-top: 16px;">
        <h3 style="margin: 0 0 8px;">Statut repush SEO</h3>
        <p class="muted" v-if="!seoRepushStatus">Aucun statut disponible.</p>
        <template v-else>
          <p class="muted" style="margin: 0 0 6px;">
            Dernier run: {{ seoRepushStatus.latest_run?.status || '-' }}
            | debut: {{ seoRepushStatus.latest_run?.started_at ? new Date(seoRepushStatus.latest_run.started_at).toLocaleString('fr-FR') : '-' }}
          </p>
          <p class="muted" style="margin: 0 0 6px;">
            Checkpoint: {{ seoRepushStatus.checkpoint?.has_checkpoint ? 'oui' : 'non' }}
          </p>
          <p class="muted" style="margin: 0;">
            Le champ "Reprendre apres ID" est pre-rempli avec la reprise detectee et tu peux le modifier avant de lancer.
          </p>
        </template>
      </div>

      <div class="panel" style="margin-top: 16px;">
        <h3 style="margin: 0 0 8px;">Statut reindex Yoast</h3>
        <p class="muted" v-if="!yoastReindexStatus">Aucun statut disponible.</p>
        <template v-else>
          <p class="muted" style="margin: 0 0 6px;">
            Dernier run: {{ yoastReindexStatus.latest_run?.status || '-' }}
            | debut: {{ yoastReindexStatus.latest_run?.started_at ? new Date(yoastReindexStatus.latest_run.started_at).toLocaleString('fr-FR') : '-' }}
          </p>
          <p class="muted" style="margin: 0;">
            Checkpoint: {{ yoastReindexStatus.checkpoint?.has_checkpoint ? 'oui' : 'non' }}
          </p>
        </template>
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
                <button class="btn btn-mini btn-linkedin" :disabled="newsStore.actionLoading" @click.stop="newsStore.postToLinkedIn(row.id)">LinkedIn</button>
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

    <article class="panel">
      <h2>Logs traitements</h2>
      <div class="actions-row between">
        <p class="muted">Derniers runs (emails + publish pending).</p>
        <button class="btn btn-ghost" :disabled="processLogsLoading" @click="newsStore.fetchProcessLogs()">
          Rafraichir logs
        </button>
      </div>

      <div class="table-wrap">
        <table style="min-width: 960px;">
          <thead>
            <tr>
              <th>ID</th>
              <th>Type</th>
              <th>Source</th>
              <th>Statut</th>
              <th>Debut</th>
              <th>Fin</th>
              <th>Message</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="processLogsLoading">
              <td colspan="7">Chargement...</td>
            </tr>
            <tr v-if="!processLogsLoading && processLogs.length === 0">
              <td colspan="7">Aucun log</td>
            </tr>
            <template v-for="log in processLogs" :key="log.id">
              <tr>
                <td>{{ log.id }}</td>
                <td><span class="pill">{{ log.process_type }}</span></td>
                <td>{{ log.source || '-' }}</td>
                <td>
                  <span
                    :class="[
                      'pill',
                      log.status === 'success'
                        ? 'status-2'
                        : log.status === 'partial'
                          ? 'status-0'
                          : log.status === 'failed'
                            ? 'error'
                            : 'status-1'
                    ]"
                  >
                    {{ log.status }}
                  </span>
                </td>
                <td>{{ log.started_at ? new Date(log.started_at).toLocaleString('fr-FR') : '-' }}</td>
                <td>{{ log.finished_at ? new Date(log.finished_at).toLocaleString('fr-FR') : '-' }}</td>
                <td class="truncate" :title="log.message || ''">{{ log.message || '-' }}</td>
              </tr>
              <tr v-if="log.details">
                <td colspan="7">
                  <details>
                    <summary class="muted">Details</summary>
                    <pre style="white-space: pre-wrap; margin: 8px 0 0;">{{ log.details }}</pre>
                  </details>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
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

    <article class="panel" v-if="preview && previewItem">
      <h2>SEO Preview & Character Counts</h2>
      
      <!-- SEO Warnings -->
      <div v-if="newsStore.previewSeoWarnings.length > 0" class="panel panel-warning">
        <strong>⚠️ SEO Warnings:</strong>
        <ul style="margin: 0.5rem 0 0 1.5rem;">
          <li v-for="(warning, i) in newsStore.previewSeoWarnings" :key="i" style="margin: 0.25rem 0; color: #d97706;">
            {{ warning }}
          </li>
        </ul>
      </div>
      
      <!-- Title -->
      <div style="margin-top: 1rem;">
        <h3 style="font-size: 0.9rem; margin: 0.5rem 0; color: #666; text-transform: uppercase; letter-spacing: 0.05em;">Title (max 62 chars)</h3>
        <div style="background: #f5f5f5; padding: 0.5rem; border-radius: 4px; margin-bottom: 0.5rem;">
          <p style="margin: 0; word-break: break-word;">{{ previewItem.title }}</p>
        </div>
        <p style="margin: 0; font-size: 0.85rem; color: #999;">
          {{ previewItem.title.length }}/62 characters
          <span v-if="previewItem.title.length > 62" style="color: #dc2626; margin-left: 0.5rem;">❌ Too long</span>
          <span v-else-if="previewItem.title.length > 55" style="color: #ea580c; margin-left: 0.5rem;">⚠️ Getting long</span>
          <span v-else style="color: #16a34a; margin-left: 0.5rem;">✓ OK</span>
        </p>
      </div>
      
      <!-- Meta Description -->
      <div style="margin-top: 1rem;">
        <h3 style="font-size: 0.9rem; margin: 0.5rem 0; color: #666; text-transform: uppercase; letter-spacing: 0.05em;">Meta Description (107–142 chars)</h3>
        <div style="background: #f5f5f5; padding: 0.5rem; border-radius: 4px; margin-bottom: 0.5rem;">
          <p style="margin: 0; word-break: break-word;">{{ previewItem.metadescription }}</p>
        </div>
        <p style="margin: 0; font-size: 0.85rem; color: #999;">
          {{ previewItem.metadescription.length }}/107-142 characters
          <span v-if="previewItem.metadescription.length < 107 || previewItem.metadescription.length > 142" style="color: #dc2626; margin-left: 0.5rem;">❌ Out of range</span>
          <span v-else style="color: #16a34a; margin-left: 0.5rem;">✓ OK</span>
        </p>
      </div>
      
      <!-- Focus Keyphrase -->
      <div style="margin-top: 1rem;">
        <h3 style="font-size: 0.9rem; margin: 0.5rem 0; color: #666; text-transform: uppercase; letter-spacing: 0.05em;">Focus Keyphrase (2–5 words)</h3>
        <div style="background: #f5f5f5; padding: 0.5rem; border-radius: 4px; margin-bottom: 0.5rem;">
          <p style="margin: 0; word-break: break-word;">{{ previewItem.focuskeyphrase }}</p>
        </div>
        <p style="margin: 0; font-size: 0.85rem; color: #999;">
          {{ previewItem.focuskeyphrase.trim().split(/\s+/).filter((w: string) => w.length > 0).length }} words
          <span v-if="previewItem.focuskeyphrase.length === 0" style="color: #dc2626; margin-left: 0.5rem;">❌ Empty</span>
          <span v-else-if="previewItem.focuskeyphrase.trim().split(/\s+/).filter((w: string) => w.length > 0).length < 2 || previewItem.focuskeyphrase.trim().split(/\s+/).filter((w: string) => w.length > 0).length > 5" style="color: #dc2626; margin-left: 0.5rem;">❌ Invalid</span>
          <span v-else style="color: #16a34a; margin-left: 0.5rem;">✓ OK</span>
        </p>
      </div>

      <!-- HTML Preview -->
      <div style="margin-top: 1.5rem;">
        <h3 style="font-size: 0.9rem; margin: 0.5rem 0; color: #666; text-transform: uppercase; letter-spacing: 0.05em;">HTML Content Preview</h3>
        <div class="preview" v-html="preview" />
      </div>
    </article>

    <!-- Mails Ignorés -->
    <article class="panel">
      <h2>Mails Ignorés</h2>
      <p class="muted">Emails rejetés par le filtre de pertinence. Le bouton "Force Publish" re-traite le mail et le publie directement.</p>

      <div class="toolbar-grid">
        <label>
          <span>Recherche (sujet / expéditeur)</span>
          <input v-model="ignoredEmailsFilters.q" type="text" placeholder="sujet ou adresse..." />
        </label>
        <label>
          <span>Statut</span>
          <select v-model="ignoredEmailsFilters.force_published">
            <option value="">Tous</option>
            <option value="0">Non publiés</option>
            <option value="1">Déjà force-publiés</option>
          </select>
        </label>
      </div>

      <div class="actions-row">
        <button class="btn btn-primary" :disabled="ignoredEmailsLoading" @click="applyIgnoredFilters">Appliquer</button>
        <button class="btn btn-ghost" :disabled="ignoredEmailsLoading" @click="clearIgnoredFilters">Reset</button>
        <button class="btn btn-ghost" :disabled="ignoredEmailsLoading" @click="newsStore.fetchIgnoredEmails(ignoredEmailsPagination.current_page)">Rafraîchir</button>
      </div>

      <div class="actions-row between" style="margin-top: 8px;">
        <p class="muted">Total : {{ ignoredEmailsPagination.total }} | Page {{ ignoredEmailsPagination.current_page }} / {{ ignoredEmailsPagination.last_page }}</p>
      </div>

      <div class="table-wrap">
        <table style="min-width: 960px;">
          <thead>
            <tr>
              <th>ID</th>
              <th>Sujet</th>
              <th>Expéditeur</th>
              <th>Raison</th>
              <th>Ignoré le</th>
              <th>Force-publié</th>
              <th>Aperçu</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="ignoredEmailsLoading">
              <td colspan="8">Chargement...</td>
            </tr>
            <tr v-else-if="!ignoredEmailsLoading && ignoredEmails.length === 0">
              <td colspan="8">Aucun mail ignoré</td>
            </tr>
            <tr v-for="item in ignoredEmails" :key="item.id">
              <td>{{ item.id }}</td>
              <td class="truncate" :title="item.subject ?? ''">{{ item.subject || '-' }}</td>
              <td class="truncate" :title="item.sender ?? ''">{{ item.sender || '-' }}</td>
              <td><span class="pill">{{ item.reason }}</span></td>
              <td>{{ item.processed_at ? new Date(item.processed_at).toLocaleString('fr-FR') : '-' }}</td>
              <td>
                <span v-if="item.force_published_at" class="pill status-2" :title="new Date(item.force_published_at).toLocaleString('fr-FR')">
                  oui
                </span>
                <span v-else class="pill status-0">non</span>
              </td>
              <td>
                <details v-if="item.excerpt">
                  <summary class="muted" style="cursor:pointer;">voir</summary>
                  <pre style="white-space: pre-wrap; font-size: 0.8rem; max-height: 120px; overflow: auto; margin: 4px 0 0;">{{ item.excerpt }}</pre>
                </details>
                <span v-else class="muted">-</span>
              </td>
              <td>
                <button
                  class="btn btn-mini btn-warning"
                  :disabled="actionLoading || !!item.force_published_at"
                  :title="item.force_published_at ? 'Déjà force-publié' : 'Re-traiter et publier ce mail'"
                  @click="newsStore.forcePublishIgnoredEmail(item.id)"
                >
                  {{ item.force_published_at ? 'Déjà publié' : 'Force Publish' }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="actions-row between">
        <button
          class="btn btn-ghost"
          :disabled="ignoredEmailsPagination.current_page <= 1"
          @click="newsStore.fetchIgnoredEmails(ignoredEmailsPagination.current_page - 1)"
        >
          Page précédente
        </button>
        <button
          class="btn btn-ghost"
          :disabled="ignoredEmailsPagination.current_page >= ignoredEmailsPagination.last_page"
          @click="newsStore.fetchIgnoredEmails(ignoredEmailsPagination.current_page + 1)"
        >
          Page suivante
        </button>
      </div>
    </article>

    <!-- LinkedIn Configuration -->
    <article class="panel">
      <h2>Configuration LinkedIn</h2>
      <p class="muted">Connectez votre compte LinkedIn une seule fois pour activer la publication d'articles.</p>

      <!-- Status badges -->
      <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 18px;">
        <span
          :style="{
            display: 'inline-block',
            padding: '3px 10px',
            borderRadius: '999px',
            fontSize: '0.8rem',
            fontWeight: '600',
            background: linkedinSettings?.token_configured ? '#dcfce7' : '#fee2e2',
            color: linkedinSettings?.token_configured ? '#16a34a' : '#dc2626',
          }"
        >
          Token : {{ linkedinSettings?.token_configured ? "Configuré ✓" : "Non configuré" }}
        </span>
        <span
          v-if="linkedinSettings?.token_expires_at"
          style="display:inline-block;padding:3px 10px;border-radius:999px;font-size:0.8rem;font-weight:600;background:#dbeafe;color:#1d4ed8;"
        >
          Expire le {{ linkedinSettings.token_expires_at }}
        </span>
        <span
          :style="{
            display: 'inline-block',
            padding: '3px 10px',
            borderRadius: '999px',
            fontSize: '0.8rem',
            fontWeight: '600',
            background: linkedinSettings?.author_urn ? '#dcfce7' : '#fee2e2',
            color: linkedinSettings?.author_urn ? '#16a34a' : '#dc2626',
          }"
        >
          Page : {{ linkedinSettings?.author_urn ? (linkedinSettings.author_name || linkedinSettings.author_urn) + " ✓" : "Non configurée" }}
        </span>
      </div>

      <!-- Step-by-step flow -->
      <div style="display: flex; gap: 14px; flex-wrap: wrap; align-items: flex-start;">

        <div style="display:flex;flex-direction:column;gap:6px;min-width:160px;">
          <span style="font-size:0.78rem;font-weight:700;color:#64748b;">ÉTAPE 1</span>
          <button class="btn btn-linkedin" :disabled="linkedinLoading" @click="openLinkedinAuth">
            Autoriser LinkedIn
          </button>
          <span style="font-size:0.72rem;color:#94a3b8;">Ouvre une fenêtre OAuth</span>
        </div>

        <div style="display:flex;flex-direction:column;gap:6px;min-width:160px;">
          <span style="font-size:0.78rem;font-weight:700;color:#64748b;">ÉTAPE 2</span>
          <button class="btn btn-ghost" :disabled="linkedinLoading" @click="checkLinkedinToken">
            Vérifier le token
          </button>
          <span style="font-size:0.72rem;color:#94a3b8;">Après avoir autorisé</span>
        </div>

        <div style="display:flex;flex-direction:column;gap:6px;min-width:180px;">
          <span style="font-size:0.78rem;font-weight:700;color:#64748b;">ÉTAPE 3</span>
          <button
            class="btn btn-ghost"
            :disabled="linkedinLoading || !linkedinSettings?.token_configured"
            @click="fetchLinkedinPages"
          >
            Récupérer mes pages
          </button>
          <span style="font-size:0.72rem;color:#94a3b8;">Liste vos pages LinkedIn administrées</span>
        </div>

        <!-- Step 4 — org list -->
        <div v-if="linkedinOrgs.length > 0" style="display:flex;flex-direction:column;gap:8px;min-width:260px;">
          <span style="font-size:0.78rem;font-weight:700;color:#64748b;">ÉTAPE 4 — Sélectionner la page</span>
          <div
            v-for="org in linkedinOrgs"
            :key="org.urn"
            style="display:flex;align-items:center;gap:10px;background:#f1f5f9;padding:8px 12px;border-radius:8px;"
          >
            <span style="flex:1;font-size:0.88rem;font-weight:600;color:#1e293b;">{{ org.name }}</span>
            <code style="font-size:0.72rem;color:#64748b;">{{ org.urn }}</code>
            <button class="btn btn-linkedin" :disabled="linkedinLoading" @click="selectLinkedinOrg(org)">
              Sélectionner
            </button>
          </div>
        </div>

      </div>

      <p v-if="linkedinMsg" style="color:#16a34a;margin:12px 0 0;font-size:0.88rem;">{{ linkedinMsg }}</p>
      <p v-if="linkedinErr" style="color:#dc2626;margin:12px 0 0;font-size:0.88rem;">{{ linkedinErr }}</p>
    </article>

    <article v-if="message" class="panel panel-success">{{ message }}</article>
    <article v-if="error" class="panel panel-error">{{ error }}</article>

    <!-- Processing Modal Overlay -->
    <div v-if="isProcessing" style="
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(0, 0, 0, 0.5);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 1000;
    ">
      <div style="
        background: white;
        padding: 2rem;
        border-radius: 8px;
        max-width: 400px;
        width: 90%;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
      ">
        <h2 style="margin-top: 0;">Processing News</h2>
        <p style="color: #666; margin: 0.5rem 0;">{{ processProgress.message }}</p>
        <p style="color: #999; font-size: 0.9rem; margin: 0.5rem 0;">
          Stage: <strong>{{ processProgress.stage }}</strong>
        </p>

        <!-- Progress Bar -->
        <div style="
          width: 100%;
          height: 24px;
          background: #e5e7eb;
          border-radius: 4px;
          overflow: hidden;
          margin: 1rem 0;
        ">
          <div :style="{
            height: '100%',
            background: 'linear-gradient(90deg, #3b82f6, #1d4ed8)',
            width: processProgress.current + '%',
            transition: 'width 0.3s ease',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            color: 'white',
            fontSize: '0.8rem',
            fontWeight: '600'
          }">
            {{ Math.round(processProgress.current) }}%
          </div>
        </div>

        <p style="text-align: center; color: #999; font-size: 0.85rem; margin: 0;">
          Please wait...
        </p>
      </div>
    </div>
  </section>
</template>
