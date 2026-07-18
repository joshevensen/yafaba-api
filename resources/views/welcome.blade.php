<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name') }} — A Flesh and Blood companion app</title>
        <meta name="description" content="YaFaBa is an iOS app and open API for Flesh and Blood: learn your class, explore cards with real explanations, build a deck with live combo suggestions, and play a match.">

        <style>
            :root {
                color-scheme: light dark;
                --bg: #ffffff;
                --fg: #1b1a17;
                --muted: #5b584f;
                --accent: #8b1e2b;
                --border: #e7e3da;
            }

            @media (prefers-color-scheme: dark) {
                :root {
                    --bg: #14120f;
                    --fg: #f2efe7;
                    --muted: #b9b4a6;
                    --accent: #e2596a;
                    --border: #2c2a24;
                }
            }

            * { box-sizing: border-box; }

            body {
                margin: 0;
                background: var(--bg);
                color: var(--fg);
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
                line-height: 1.55;
            }

            main {
                max-width: 720px;
                margin: 0 auto;
                padding: 4rem 1.5rem 5rem;
            }

            h1 {
                font-size: 2.25rem;
                margin: 0 0 0.5rem;
            }

            .tagline {
                color: var(--muted);
                font-size: 1.15rem;
                margin: 0 0 2.5rem;
            }

            .pillars {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
                gap: 1rem;
                margin: 2rem 0;
                padding: 0;
                list-style: none;
            }

            .pillars li {
                border: 1px solid var(--border);
                border-radius: 0.75rem;
                padding: 1rem;
            }

            .pillars strong {
                display: block;
                margin-bottom: 0.25rem;
            }

            .cta {
                display: inline-flex;
                gap: 0.75rem;
                flex-wrap: wrap;
                margin: 2rem 0;
            }

            .cta a {
                display: inline-block;
                padding: 0.65rem 1.25rem;
                border-radius: 0.5rem;
                text-decoration: none;
                font-weight: 600;
                border: 1px solid var(--border);
            }

            .cta a.primary {
                background: var(--accent);
                border-color: var(--accent);
                color: #fff;
            }

            .cta a:not(.primary) {
                color: var(--fg);
            }

            footer {
                margin-top: 3rem;
                padding-top: 1.5rem;
                border-top: 1px solid var(--border);
                color: var(--muted);
                font-size: 0.85rem;
            }

            footer p {
                margin: 0.4rem 0;
            }
        </style>
    </head>
    <body>
        <main>
            <h1>{{ config('app.name') }}</h1>
            <p class="tagline">An iOS app and open API for Flesh and Blood, built around one idea: help a player understand their cards and their class well enough to become a pro and stay one.</p>

            <ul class="pillars">
                <li><strong>Learn</strong>Get oriented — find your class, tiered how-to-play, class guides.</li>
                <li><strong>Explore</strong>Card lookup with real explanations and synergy info.</li>
                <li><strong>Build</strong>Guided deckbuilding, proxies, registration sheets.</li>
                <li><strong>Play</strong>Play a match, mobile-native, deterministic in-game reminders.</li>
            </ul>

            <div class="cta">
                <a class="primary" href="https://github.com/joshevensen/yafaba-api">View the code</a>
                <a href="https://github.com/joshevensen/yafaba-api/blob/main/docs/app-design.md">Read the design docs</a>
            </div>

            <footer>
                <p>YaFaBa is in no way affiliated with Legend Story Studios. Flesh and Blood™, and set names are trademarks of Legend Story Studios®.</p>
                <p>Card images and text © Legend Story Studios.</p>
            </footer>
        </main>
    </body>
</html>
