# Mise en ligne — news.aeromorning.com (Nuxt) + API Laravel

Objectif :
- Front (Nuxt **client-side**) sur `https://news.aeromorning.com`
- API Laravel sur un domaine dédié (au choix) :
  - `https://news.api.aeromorning.com` (recommandé)
  - `https://api.aeromorning.com` (ton cas)
- Pas de blocage CORS / préflight, et `Authorization: Bearer ...` bien transmis

---

## 1) Variables d’environnement

### API (Laravel)
Dans `api/.env` (production) :
- `APP_URL=https://news.api.aeromorning.com` (ou `https://api.aeromorning.com`)
- `CORS_ALLOWED_ORIGINS=https://news.aeromorning.com`

Notes :
- Sanctum fonctionne déjà en **Bearer token** (Authorization header) → pas besoin de cookies.
- Si un jour tu passes en auth SPA par cookies Sanctum, tu devras aussi configurer :
  - `SANCTUM_STATEFUL_DOMAINS=news.aeromorning.com,news.api.aeromorning.com`
  - `SESSION_DOMAIN=.aeromorning.com`

### Front (Nuxt)
Dans `gestion-news/.env` (production) :
- `NUXT_PUBLIC_API_BASE_URL=https://news.api.aeromorning.com/api` (ou `https://api.aeromorning.com/api`)

---

## 2) Nuxt — choisir le mode de déploiement

Nuxt 3 peut se déployer de 2 façons :

### Option A (recommandée) — serveur Node (Nitro)
Tu utilises `nuxt build` puis tu sers l’app via `.output/server/index.mjs`.
Dans ce mode, la présence du répertoire `.output/server` est **normale** (même si `ssr:false`).
Avantage : `NUXT_PUBLIC_API_BASE_URL` peut être fourni au **runtime** (au démarrage du process).

Dans `gestion-news/` :
- `npm ci`
- `npm run build`

Puis démarrer le serveur Nuxt (Nitro) :
- `npm run start`

### Option B — statique (index.html)
Si tu veux un vrai site statique (servi par Apache/Nginx en simple DocumentRoot) avec `index.html`,
utilise :
- `npm ci`
- `set NUXT_PUBLIC_API_BASE_URL=https://news.api.aeromorning.com/api` (Windows) / `export ...` (Linux)
- `npm run generate`

Dans ce mode, `NUXT_PUBLIC_API_BASE_URL` est **injecté à la génération** (build-time),
donc il faut que la variable soit correcte au moment du `generate`.

Rappel :
- `nuxt build` => `.output/server` + `.output/public` (pas garanti d’avoir un `index.html` exploitable en static)
- `nuxt generate` => `.output/public` avec `index.html` (statique)

---

## 3) Apache vhosts (exemple)

### 3.1 API → Laravel `public/`

**DocumentRoot** doit pointer sur : `extract_news/api/public`

Exemple vhost (Apache) :

```apache
<VirtualHost *:80>
  ServerName news.api.aeromorning.com
  # ou
  # ServerName api.aeromorning.com
  DocumentRoot "C:/xampp8/htdocs/extract_news/api/public"

  <Directory "C:/xampp8/htdocs/extract_news/api/public">
    AllowOverride All
    Require all granted
  </Directory>

  # Important: ne pas perdre le header Authorization (Bearer)
  SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1

  ErrorLog "logs/api-error.log"
  CustomLog "logs/api-access.log" combined
</VirtualHost>
```

En HTTPS : ajoute le vhost `*:443` avec tes certificats (Let’s Encrypt / autre).

### 3.2 news.aeromorning.com → Nuxt statique

### 3.2 news.aeromorning.com → reverse proxy vers Nuxt (Node)

1) Lancer Nuxt (sur le serveur) :
- `cd gestion-news`
- `npm ci`
- `npm run build`
- `set PORT=3000` (Windows) / `export PORT=3000` (Linux)
- `set NUXT_PUBLIC_API_BASE_URL=https://news.api.aeromorning.com/api` (Windows) / `export ...` (Linux)
- `npm run start`

2) Apache reverse proxy (exemple) :

```apache
<VirtualHost *:80>
  ServerName news.aeromorning.com

  ProxyPreserveHost On
  ProxyPass / http://127.0.0.1:3000/
  ProxyPassReverse / http://127.0.0.1:3000/

  ErrorLog "logs/news.aeromorning.com-error.log"
  CustomLog "logs/news.aeromorning.com-access.log" combined
</VirtualHost>
```

Modules Apache requis : `proxy`, `proxy_http`.

---

## 4) Nginx (alternative)

### API
```nginx
server {
  server_name news.api.aeromorning.com;
  root /var/www/extract_news/api/public;

  location / {
    try_files $uri $uri/ /index.php?$query_string;
  }

  location ~ \.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_param HTTP_AUTHORIZATION $http_authorization;
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
  }
}
```

### Nuxt (reverse proxy)
```nginx
server {
  server_name news.aeromorning.com;

  location / {
    proxy_pass http://127.0.0.1:3000;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
  }
}
```

---

## 5) Check-list rapide

- `https://news.api.aeromorning.com/api/auth/login` (ou `https://api.aeromorning.com/api/auth/login`) répond (200/422) et renvoie bien un token.
- Depuis `https://news.aeromorning.com`, les appels à l’API ne sont pas bloqués (préflight OK).
- Les endpoints protégés passent avec `Authorization: Bearer <token>`.
- Si tu vois un 401 alors que le token est bon → vérifier transmission du header Authorization (Apache/Nginx config ci-dessus).

### Si tu as un 500 sur `/api/auth/login`
Le CORS n’est pas en cause (CORS bloquerait côté navigateur avant même de toucher ton code). Pour un 500, vérifie :
- `storage/logs/laravel.log` sur le serveur (tu auras l’exception exacte).
- Migrations faites en prod (Sanctum) : `php artisan migrate --force`.
  - Le symptôme le plus fréquent : table `personal_access_tokens` absente → erreur SQL au moment de `createToken()`.
