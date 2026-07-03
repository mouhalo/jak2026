# Audit SEO & Performance — jak.sn

**Site audité :** https://jak.sn (Visite de Cheikh Ould Khaiïry — 16·17·18 Juillet 2026, CICES Dakar)
**Auditeur :** agent `seo-perf-expert` (Awa Ndiaye, SEO & Web Performance Senior)
**Date :** 3 juillet 2026
**Méthode :** mesures réelles `curl` (TTFB, en-têtes, poids des ressources) + analyse du code source. Les points marqués *(estimé)* nécessitent une passe Lighthouse live.

---

## 1. Résumé exécutif

Le site repose sur d'**excellentes fondations techniques** : hébergement rapide (LiteSpeed/N0C), HTTP/2 **et** HTTP/3, compression gzip active, JS/CSS légers (< 7 Ko chacun compressés), et un front responsive soigné. **Mais il est aujourd'hui quasi invisible pour le référencement et catastrophique au partage social** — ce qui est critique pour une audience qui arrive massivement par **WhatsApp**.

Trois risques majeurs :
1. **Contenu rendu côté client** : les `<h1>` et le contenu de l'accueil et des dignitaires sont vides dans le HTML servi (injectés en JS) → **invisibles pour Google (partiellement) et totalement pour les robots WhatsApp/Facebook/Twitter**.
2. **Zéro balise Open Graph** : un lien jak.sn partagé sur WhatsApp s'affiche sans titre, sans image, sans description → très faible taux de clic.
3. **Bases SEO absentes** : `robots.txt` et `sitemap.xml` en 404, pas de redirection HTTP→HTTPS, sous-domaine `www` cassé (11 s), pas de `meta description`, contenu encore en `[À COMPLÉTER]`.

**Potentiel de gain :** rapide et élevé. La majorité des correctifs sont des ajouts statiques (balises `<head>`, `.htaccess`, `robots.txt`, JSON-LD) réalisables en une demi-journée, avant le pic de trafic du jour J.

---

## 2. Scorecard

| Axe | Note /100 | Donnée mesurée justifiant la note |
|---|---:|---|
| SEO technique | **35** | `robots.txt` 404, `sitemap.xml` 404, `http://` non redirigé (200), `www` en 11,3 s, pas de canonical |
| SEO on-page | **45** | `<title>` OK & uniques ✓ ; mais aucune `meta description`, `<h1>` d'accueil vide, contenu `[À COMPLÉTER]` |
| Partage social / OG | **5** | Aucune balise `og:*` ni `twitter:*` sur aucune page |
| Performance | **60** | gzip + HTTP/2-3 ✓, texte < 7 Ko ✓ ; mais `image_cheikh.png` = **416 Ko** (hero), pas de `cache-control`, galerie ~59 Mo |
| Responsivité | **80** *(estimé)* | `<meta viewport>` ✓, CSS mobile-first + tap targets ≥ 44 px + `dvh`/`safe-area` (à confirmer en Lighthouse mobile) |
| Accessibilité | **70** | `prefers-reduced-motion` ✓, contrastes ≥ 4.5:1 ✓ ; `alt` de galerie souvent vides |
| Sécurité (en-têtes) | **30** | Aucun HSTS / X-Content-Type-Options / CSP / X-Frame-Options ; HTTPS non forcé |
| **Global** | **≈ 46** | Bonnes fondations, invisibilité SEO & sociale critique |

---

## 3. Backlog priorisé

> **P0** = bloquant (à corriger avant toute promotion du lien) · **P1** = fort impact · **P2** = confort/optimisation.

| P | Constat mesuré | Recommandation | Impact attendu | Effort |
|---|---|---|---|---|
| **P0** | Lien partagé = aperçu vide (aucune balise `og:*`) | Ajouter Open Graph + Twitter Card + image de partage 1200×630 dans chaque `<head>` | Aperçus riches sur WhatsApp/FB → **taux de clic ×2-3** | S |
| **P0** | `<h1 id="chNom">` et contenu injectés en JS → HTML servi vide | Écrire en dur dans le HTML le titre, un paragraphe d'intro et les balises OG (contenu figé : nom, dates, lieu) ; laisser le JS enrichir | Indexation + partage fiables même sans exécution JS | M |
| **P0** | `robots.txt` **404**, `sitemap.xml` **404** | Créer les deux fichiers (sitemap listant les 5 pages publiques, `admin.html` exclu) | Exploration correcte, soumission Search Console | S |
| **P0** | `http://jak.sn` répond **200** (pas de redirection) ; `www` en **11,3 s** | `.htaccess` : forcer HTTPS + 301 `www`→non-`www` (canonicalisation) | Une seule URL canonique, pas de contenu dupliqué, HTTPS partout | S |
| **P1** | Aucune `meta description` sur les 5 pages | Rédiger une description unique (~155 car.) par page | Meilleur CTR sur Google | S |
| **P1** | Contenu `data.js` = `[À COMPLÉTER]` (bio, citations, programme, membres) | Compléter le contenu réel (contenu = 1er facteur SEO) | Pages indexables à valeur ajoutée | M |
| **P1** | `image_cheikh.png` = **416 Ko** PNG en hero (LCP) | Convertir en WebP/AVIF ≤ 80 Ko + `width/height` + `fetchpriority="high"` | **LCP fortement réduit** *(estimé)*, moins de data mobile | S |
| **P1** | Pas de données structurées | JSON-LD `Event` (dates, lieu CICES, organisateur JAK) sur l'accueil | Éligibilité aux rich results « Événement » Google | S |
| **P1** | Réponses sans `cache-control` (seul `last-modified`) | `.htaccess` : `Cache-Control` long + `immutable` sur CSS/JS/images | Chargements répétés instantanés, moins de charge le jour J | S |
| **P1** | Aucun `<link rel="icon">` | Ajouter favicon + `apple-touch-icon` (dérivés de `logo.png`) | Marque visible en onglet/écran d'accueil | S |
| **P2** | 45 photos galerie ≈ **59 Mo** non optimisées | Pipeline WebP + vignettes 320 px + `loading="lazy"` (déjà en place) | Galerie mobile fluide | M |
| **P2** | 5 langues côté client, même URL, pas de `hreflang` | À terme : URLs par langue + `hreflang` (ou au moins `og:locale:alternate`) | Indexation WO/FF/AR/EN | L |
| **P2** | Aucun en-tête de sécurité | `.htaccess` : HSTS, `X-Content-Type-Options`, `Referrer-Policy`, CSP | Signal de confiance, protection | S |
| **P2** | Aucun outil de mesure d'audience | Plausible/Matomo (sans cookies, RGPD-friendly) + Search Console | Pilotage data du jour J | S |
| **P2** | `admin.html` accessible et indexable | `noindex` + exclusion sitemap + protection (cf. `CONCEPT_NOTE.md` Lot 1) | Pas de fuite de la console d'admin | S |

---

## 4. Correctifs prêts à coller

### 4.1 Open Graph + Twitter + canonical + favicon (dans le `<head>` de chaque page)

Exemple pour `index.html` (adapter `og:title`/`description`/`url` par page) :

```html
<link rel="canonical" href="https://jak.sn/index.html">
<meta name="description" content="Visite de Cheikh Ould Khaiïry à Dakar les 16, 17 et 18 juillet 2026 au CICES. Programme, accès et informations — organisé par Jeunesse Al Khaïry.">
<link rel="icon" href="/logo.png" type="image/png">
<link rel="apple-touch-icon" href="/logo.png">

<!-- Open Graph (WhatsApp, Facebook, LinkedIn) -->
<meta property="og:type" content="website">
<meta property="og:site_name" content="Jeunesse Al Khaïry">
<meta property="og:title" content="Visite de Cheikh Ould Khaiïry — 16·17·18 Juillet 2026, CICES Dakar">
<meta property="og:description" content="Programme, accès et informations sur la visite du Cheikh. Organisé par Jeunesse Al Khaïry.">
<meta property="og:url" content="https://jak.sn/">
<meta property="og:image" content="https://jak.sn/og-image.jpg"><!-- créer une image 1200×630 -->
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:locale" content="fr_FR">

<!-- Twitter/X -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="Visite de Cheikh Ould Khaiïry — Juillet 2026">
<meta name="twitter:description" content="16·17·18 juillet 2026 au CICES, Dakar. Organisé par Jeunesse Al Khaïry.">
<meta name="twitter:image" content="https://jak.sn/og-image.jpg">
```

> **Image de partage** : créer `og-image.jpg` 1200×630 (photo du Cheikh + titre + dates), ≤ 200 Ko. C'est ce qui s'affichera dans WhatsApp.

### 4.2 Données structurées `Event` (JSON-LD, à placer avant `</head>` de `index.html`)

```html
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Event",
  "name": "Visite de Cheikh Ould Khaiïry",
  "startDate": "2026-07-16T09:00:00+00:00",
  "endDate": "2026-07-18T22:00:00+00:00",
  "eventStatus": "https://schema.org/EventScheduled",
  "eventAttendanceMode": "https://schema.org/OfflineEventAttendanceMode",
  "location": {
    "@type": "Place",
    "name": "CICES",
    "address": { "@type": "PostalAddress", "addressLocality": "Dakar", "addressCountry": "SN" }
  },
  "image": ["https://jak.sn/og-image.jpg"],
  "organizer": { "@type": "Organization", "name": "Jeunesse Al Khaïry", "url": "https://jak.sn" }
}
</script>
```

### 4.3 Rendu robots-safe — contenu figé dans le HTML

Le problème : `<h1 id="chNom"></h1>` est vide côté serveur. Écrire le contenu stable en dur, le JS le remplacera si besoin :

```html
<h1 id="chNom">Cheikh Ould Khaiïry</h1>
<p id="chBio">La Jeunesse Al Khaïry accueille le Cheikh à Dakar les 16, 17 et 18 juillet 2026 au CICES…</p>
```

(Le script de `index.html` réécrit déjà ces nœuds : aucun conflit, mais le crawler voit désormais un contenu réel.)

### 4.4 `robots.txt` (à la racine)

```
User-agent: *
Allow: /
Disallow: /admin.html
Sitemap: https://jak.sn/sitemap.xml
```

### 4.5 `sitemap.xml` (à la racine — `admin.html` exclu)

```xml
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>https://jak.sn/</loc><priority>1.0</priority></url>
  <url><loc>https://jak.sn/dignitaires.html</loc><priority>0.8</priority></url>
  <url><loc>https://jak.sn/jak.html</loc><priority>0.8</priority></url>
  <url><loc>https://jak.sn/galerie.html</loc><priority>0.7</priority></url>
  <url><loc>https://jak.sn/Mon_Acces_2026.html</loc><priority>0.7</priority></url>
</urlset>
```

### 4.6 `.htaccess` (LiteSpeed/N0C) — HTTPS, canonicalisation, cache, sécurité

```apache
# Forcer HTTPS + non-www (canonique)
RewriteEngine On
RewriteCond %{HTTPS} off [OR]
RewriteCond %{HTTP_HOST} ^www\. [NC]
RewriteCond %{HTTP_HOST} ^(?:www\.)?(.+)$ [NC]
RewriteRule ^ https://%1%{REQUEST_URI} [L,R=301]

# Cache long des ressources statiques
<IfModule mod_headers.c>
  <FilesMatch "\.(css|js|png|jpe?g|webp|avif|svg|woff2?)$">
    Header set Cache-Control "public, max-age=31536000, immutable"
  </FilesMatch>
  # En-têtes de sécurité
  Header set X-Content-Type-Options "nosniff"
  Header set Referrer-Policy "strict-origin-when-cross-origin"
  Header set Strict-Transport-Security "max-age=31536000; includeSubDomains" env=HTTPS
  Header set X-Frame-Options "SAMEORIGIN"
</IfModule>

# Empêcher l'indexation de la console d'admin
<Files "admin.html">
  Header set X-Robots-Tag "noindex, nofollow"
</Files>
```

### 4.7 Google Fonts — accélérer le rendu (dans le `<head>`)

```html
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
```

### 4.8 Image hero — réduire le LCP

```html
<img class="portrait" id="chPhoto" src="souvenirs/cheikh/image_cheikh.webp"
     width="480" height="600" fetchpriority="high" alt="Cheikh Ould Khaiïry">
```
Convertir `image_cheikh.png` (416 Ko) → WebP/AVIF (viser ≤ 80 Ko). Ajouter `width/height` évite le décalage de mise en page (CLS).

---

## 5. Quick wins (< 1 h, effort minimal, fort effet)

1. Déposer `robots.txt` + `sitemap.xml` à la racine (§4.4–4.5).
2. Ajouter le `.htaccess` HTTPS + non-www (§4.6) — corrige d'un coup le `http://` non redirigé et le `www` à 11 s.
3. Coller les balises OG + une `og-image.jpg` 1200×630 (§4.1) — transforme immédiatement les partages WhatsApp.
4. Ajouter `preconnect` Google Fonts (§4.7).
5. Ajouter la `meta description` et le favicon sur les 5 pages.
6. Compresser `image_cheikh.png` et `logo.png` (WebP).

---

## 6. Plan de suivi (après correction)

- **Soumettre** le sitemap dans **Google Search Console** ; vérifier l'indexation des 5 pages.
- **Valider** les partages via *Facebook Sharing Debugger* et un test WhatsApp réel.
- **Mesurer** LCP/CLS/INP via **PageSpeed Insights** (mobile) avant/après — objectif LCP < 2,5 s.
- **Installer** Plausible/Matomo pour suivre le trafic et le taux de partage à l'approche du jour J.
- **Re-lancer cet agent** (`seo-perf-expert`) pour une passe Lighthouse live et une vérification post-correctifs.

---

### Annexe — mesures brutes (3 juillet 2026)

| Ressource | Code | TTFB / total | Poids (gzip) |
|---|---|---|---|
| `https://jak.sn/` | 200 | 0,62 s | 1 217 o |
| `http://jak.sn/` | 200 *(pas de redirection HTTPS)* | 0,23 s | — |
| `https://www.jak.sn/` | 200 *(anormalement lent)* | 11,34 s | — |
| `site.css` | 200 | 0,29 s | 4 025 o |
| `site.js` | 200 | 0,28 s | 6 712 o |
| `logo.png` | 200 | 0,46 s | 52 682 o |
| `image_cheikh.png` | 200 | 0,63 s | **416 262 o** |
| `souvenirs/image1.jpg` | 200 | 0,37 s | 30 323 o |
| `robots.txt` | **404** | — | — |
| `sitemap.xml` | **404** | — | — |

**Serveur :** LiteSpeed (N0C) · HTTP/2 + HTTP/3 (h3) · gzip actif · en-têtes de cache et de sécurité **absents**.

---

## 7. Résultats après correctifs P0 (3 juillet 2026, mesurés en ligne)

Les correctifs P0 ont été déployés puis vérifiés sur https://jak.sn.

### En-têtes & redirections (curl)

| Contrôle | Avant | Après |
|---|---|---|
| `http://jak.sn` | 200 (pas de redirection) | **301 → https://jak.sn/** |
| `https://www.jak.sn` | 200 en **11,3 s** | **301 → jak.sn en 0,31 s** |
| `robots.txt` / `sitemap.xml` | 404 / 404 | **200 / 200** |
| Open Graph / description / JSON-LD / `<h1>` | absents | **en ligne** |
| `cache-control`, `HSTS`, `nosniff`, `referrer-policy`, `x-frame` | absents | **présents** |

### Lighthouse mobile (navigation) — avant / après correctifs P1

| Catégorie | P0 seul | + correctifs P1 |
|---|---|---|
| **SEO** | 100 / 100 | **100 / 100** |
| **Best Practices** | 100 / 100 | **100 / 100** |
| **Accessibilité** | 83 / 100 | **100 / 100** ✅ |
| **LCP** (lab) | 252 ms | **134 ms** |
| **CLS** (lab) | 0,25 ⚠️ | **0,00** ✅ |

### Correctifs P1 appliqués (mesurés, vérifiés en local port 3000)

- **CLS 0,25 → 0,00** : `aspect-ratio:482/495` + `height:auto` sur l'image hero `#chPhoto` (+ `width/height` dans le HTML), `min-height` réservée sur le compte à rebours (`site.css`, `index.html`).
- **Accessibilité 83 → 100** :
  1. Puces du carrousel : `aria-label="Diapositive N"` + `type="button"` (`site.js`).
  2. Cibles tactiles : puces portées à **24×24 px** (dot visuel de 10 px via `::before`), `site.css`.
  3. Contraste : couleur `.todo` éclaircie (`#e6a878`) pour passer ≥ 4.5:1.

### Points restants (P2, non bloquants)

Image de partage 1200×630 dédiée, WebP (hero + galerie), contenu `[À COMPLÉTER]` à compléter, hreflang multilingue, analytics privacy-friendly.

