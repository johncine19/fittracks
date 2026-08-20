# FitTrack Loading Page (GitHub Pages)

This is a static loading page that shields your users from Render's cold-start screen.

## How It Works

1. Users visit your GitHub Pages URL (e.g., `https://yourusername.github.io/fittrack-loading/`)
2. They see a beautiful branded FitTrack loading screen
3. JavaScript pings your Render backend every 3 seconds
4. Once the server responds, it auto-redirects to the real app
5. Users **never** see Render's cold-start screen

## Setup Instructions

### Step 1: Update the Render URL

Open `index.html` and update the `RENDER_APP_URL` constant (line ~265):

```javascript
const RENDER_APP_URL = 'https://YOUR-APP-NAME.onrender.com';
```

Replace `YOUR-APP-NAME` with your actual Render app name.

### Step 2: Deploy to GitHub Pages

**Option A: Separate Repository (Recommended)**

1. Create a new GitHub repo (e.g., `fittrack-loading`)
2. Copy the `loading-page/index.html` file to the repo root as `index.html`
3. Go to **Settings → Pages** → set source to `main` branch
4. Your loading page is now live at `https://yourusername.github.io/fittrack-loading/`

**Option B: Use `gh-pages` branch in your existing repo**

1. Create an orphan branch:
   ```bash
   git checkout --orphan gh-pages
   git rm -rf .
   ```
2. Copy `loading-page/index.html` as `index.html`
3. Commit and push:
   ```bash
   git add index.html
   git commit -m "Add loading page"
   git push origin gh-pages
   ```
4. Enable GitHub Pages on the `gh-pages` branch
5. Switch back to your main branch: `git checkout main`

### Step 3: Share the GitHub Pages URL

Share the GitHub Pages URL with your users instead of the direct Render URL.

If you have a custom domain, you can point it to GitHub Pages and have it redirect to Render — that way users always see your branded loading screen.

## Configuration Options

In `index.html`, you can tweak these constants:

| Constant | Default | Description |
|---|---|---|
| `RENDER_APP_URL` | *(must set)* | Your Render app URL |
| `MAX_ATTEMPTS` | `60` | Max ping attempts before showing error (60 × 3s = 3 min) |
| `PING_INTERVAL` | `3000` | Milliseconds between pings |
| `REDIRECT_DELAY` | `800` | Milliseconds to wait for exit animation before redirect |
