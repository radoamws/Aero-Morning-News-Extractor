# LinkedIn Publishing — Aide-mémoire

## Architecture

| Fichier                                             | Rôle                                            |
| --------------------------------------------------- | ------------------------------------------------ |
| `api/app/Services/LinkedInService.php`            | OAuth + post UGC + génération du texte         |
| `api/app/Http/Controllers/LinkedInController.php` | Endpoints HTTP                                   |
| `api/routes/api.php`                              | Routes LinkedIn (sous`auth:sanctum`)           |
| `api/storage/app/linkedin.json`                   | Stockage persistant du token + URN (auto-créé) |
| `gestion-news/stores/news.ts`                     | Action`postToLinkedIn(id)`                     |
| `gestion-news/pages/dashboard.vue`                | Bouton LinkedIn par ligne + panel de config      |
| `gestion-news/assets/css/main.css`                | Style`.btn-linkedin` (bleu #0077b5)            |

---

## Variables `.env`

```env
LINKEDIN_CLIENT_ID=
LINKEDIN_CLIENT_SECRET=
LINKEDIN_REDIRECT_URI=https://aeromorning.com/api/linkedin/callback

# Ces deux champs sont auto-remplis via le dashboard (panel "Configuration LinkedIn")
# Ils peuvent aussi être mis manuellement ici en fallback
LINKEDIN_ACCESS_TOKEN=
LINKEDIN_AUTHOR_URN=
```

> **Priorité** : `api/storage/app/linkedin.json` > `.env`. Le dashboard écrit dans le fichier JSON.

---

## Premiers pas (initialisation)

### 1. Configurer l'app LinkedIn Developer

Sur https://www.linkedin.com/developers/apps > votre app :

- Onglet **Auth** → **Authorized redirect URLs** :
  ```
  https://aeromorning.com/api/linkedin/callback
  ```
- Onglet **Products** → activer **Share on LinkedIn** (scope `w_member_social`)

### 2. Obtenir le token via le dashboard

Dans le dashboard Gestion News, panel **"Configuration LinkedIn"** :

| Étape | Bouton                           | Ce que ça fait                                   |
| ------ | -------------------------------- | ------------------------------------------------- |
| 1      | **Autoriser LinkedIn**     | Ouvre une popup OAuth LinkedIn                    |
| 2      | **Vérifier le token**     | Vérifie que le token a été sauvegardé         |
| 3      | **Récupérer mon profil** | Appelle`/linkedin/auth-info`, affiche votre URN |
| 4      | **Sauvegarder**            | Écrit l'URN dans`storage/app/linkedin.json`    |

Après l'étape 4, le bouton **LinkedIn** (bleu) sur chaque ligne de news est opérationnel.

---

## Renouveler le token (tous les 60 jours)

Le token LinkedIn expire au bout de **60 jours**. Pour le renouveler :

1. Aller dans le dashboard → panel "Configuration LinkedIn"
2. Refaire les étapes 1 et 2 (Autoriser + Vérifier)
3. L'étape 3 et 4 ne sont plus nécessaires si l'URN est déjà sauvegardé

La date d'expiration est affichée dans les badges du panel.

---

## Ce que publie le bouton LinkedIn

Pour chaque article :

1. **Texte du post** :
   ```
   ✈️ [Titre de l'article]

   [Metadescription ou extrait intelligent, 280 chars max]

   🔗 [Lien permanent WordPress]

   #Aviation #Aeromorning #Aerospace #[mots-clés du focuskeyphrase]
   ```
2. **Carte article** : LinkedIn récupère automatiquement l'OG image de l'article (miniature WordPress)
3. **Visibilité** : PUBLIC

---

## Endpoints API

Tous protégés par `auth:sanctum` (token Sanctum dans le header).

| Méthode | Route                               | Description                               |
| -------- | ----------------------------------- | ----------------------------------------- |
| `POST` | `/api/news/{id}/post-to-linkedin` | Publie l'article sur LinkedIn             |
| `GET`  | `/api/linkedin/auth`              | Retourne l'URL OAuth à ouvrir            |
| `GET`  | `/api/linkedin/callback?code=...` | Callback OAuth, sauvegarde le token       |
| `GET`  | `/api/linkedin/auth-info`         | Retourne nom + URN du compte connecté    |
| `GET`  | `/api/linkedin/settings`          | Status courant (token configuré ? URN ?) |
| `POST` | `/api/linkedin/save-urn`          | Sauvegarde l'URN dans`linkedin.json`    |

---

## Stockage des settings

Fichier : `api/storage/app/linkedin.json`

```json
{
  "access_token": "AQX...",
  "token_expires_at": "2026-08-28",
  "author_urn": "urn:li:person:AbCdEfGhIj",
  "author_name": "Rado Rakotoarivelo"
}
```

Ce fichier **ne doit pas être committé** (ajouter à `.gitignore` si nécessaire).

---

## Déploiement

```bash
# Sur le serveur prod (SSH)
git pull
php artisan config:clear && php artisan route:clear && php artisan cache:clear

# Frontend Nuxt
cd gestion-news
npm run build
```

Aucun `composer install` n'est nécessaire (pas de nouvelle dépendance Composer).

---

## Troubleshooting

| Symptôme                       | Cause probable                                  | Solution                                                            |
| ------------------------------- | ----------------------------------------------- | ------------------------------------------------------------------- |
| "Token LinkedIn non configuré" | Étapes 1-2 pas encore faites                   | Faire le flow OAuth dans le dashboard                               |
| "URN LinkedIn non configuré"   | Étapes 3-4 pas encore faites                   | Récupérer et sauvegarder le profil                                |
| "LinkedIn API error (HTTP 401)" | Token expiré                                   | Refaire l'étape 1-2 (OAuth)                                        |
| "LinkedIn API error (HTTP 422)" | Scope`w_member_social` manquant               | Activer "Share on LinkedIn" dans l'app Developer                    |
| Callback renvoie une erreur     | `redirect_uri` non enregistrée               | Vérifier dans LinkedIn Developer > Auth > Authorized redirect URLs |
| L'article n'a pas de miniature  | `wp_post_id` vide (pas encore publié sur WP) | Publier sur WordPress d'abord                                       |
