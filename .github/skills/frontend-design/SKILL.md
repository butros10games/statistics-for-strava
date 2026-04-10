---
name: frontend-design
description: "Create distinctive, production-grade frontend interfaces with high design quality. Use this skill when the user asks to build web components, pages, or applications. Generates creative, polished code that avoids generic AI aesthetics. In this repository, prefer Twig, Tailwind, Flowbite, and the existing vanilla JavaScript frontend patterns."
---

This skill guides creation of distinctive, production-grade frontend interfaces that avoid generic "AI slop" aesthetics. Implement real working code with exceptional attention to aesthetic details and creative choices.

The user provides frontend requirements: a component, page, application, or interface to build. They may include context about the purpose, audience, or technical constraints.

This project-local import is based on Anthropic's `frontend-design` skill and is lightly adapted for `statistics-for-strava`.

## Design Thinking

Before coding, understand the context and commit to a BOLD aesthetic direction:
- **Purpose**: What problem does this interface solve? Who uses it?
- **Tone**: Pick an extreme: brutally minimal, maximalist chaos, retro-futuristic, organic/natural, luxury/refined, playful/toy-like, editorial/magazine, brutalist/raw, art deco/geometric, soft/pastel, industrial/utilitarian, etc. There are so many flavors to choose from. Use these for inspiration but design one that is true to the aesthetic direction.
- **Constraints**: Technical requirements (framework, performance, accessibility).
- **Differentiation**: What makes this UNFORGETTABLE? What's the one thing someone will remember?

**CRITICAL**: Choose a clear conceptual direction and execute it with precision. Bold maximalism and refined minimalism both work - the key is intentionality, not intensity.

Then implement working code (HTML/CSS/JS, React, Vue, etc.) that is:
- Production-grade and functional
- Visually striking and memorable
- Cohesive with a clear aesthetic point-of-view
- Meticulously refined in every detail

## Frontend Aesthetics Guidelines

Focus on:
- **Typography**: Choose fonts that are beautiful, unique, and interesting. Avoid generic fonts like Arial and Inter; opt instead for distinctive choices that elevate the frontend's aesthetics; unexpected, characterful font choices. Pair a distinctive display font with a refined body font.
- **Color & Theme**: Commit to a cohesive aesthetic. Use CSS variables for consistency. Dominant colors with sharp accents outperform timid, evenly-distributed palettes.
- **Motion**: Use animations for effects and micro-interactions. Prioritize CSS-only solutions for HTML. Use Motion library for React when available. Focus on high-impact moments: one well-orchestrated page load with staggered reveals (`animation-delay`) creates more delight than scattered micro-interactions. Use scroll-triggering and hover states that surprise.
- **Spatial Composition**: Unexpected layouts. Asymmetry. Overlap. Diagonal flow. Grid-breaking elements. Generous negative space OR controlled density.
- **Backgrounds & Visual Details**: Create atmosphere and depth rather than defaulting to solid colors. Add contextual effects and textures that match the overall aesthetic. Apply creative forms like gradient meshes, noise textures, geometric patterns, layered transparencies, dramatic shadows, decorative borders, custom cursors, and grain overlays.

NEVER use generic AI-generated aesthetics like overused font families (Inter, Roboto, Arial, system fonts), cliched color schemes (particularly purple gradients on white backgrounds), predictable layouts and component patterns, and cookie-cutter design that lacks context-specific character.

Interpret creatively and make unexpected choices that feel genuinely designed for the context. No design should be the same. Vary between light and dark themes, different fonts, different aesthetics. NEVER converge on common choices (Space Grotesk, for example) across generations.

**IMPORTANT**: Match implementation complexity to the aesthetic vision. Maximalist designs need elaborate code with extensive animations and effects. Minimalist or refined designs need restraint, precision, and careful attention to spacing, typography, and subtle details. Elegance comes from executing the vision well.

Remember: Claude is capable of extraordinary creative work. Don't hold back, show what can truly be created when thinking outside the box and committing fully to a distinctive vision.

## Repository Guidance

When using this skill in `statistics-for-strava`, prefer the project's existing frontend stack and conventions over introducing a new framework.

- **Templates**: Prefer server-rendered Twig templates under `templates/html/`. Extend or compose the nearest existing feature template before creating new structure from scratch.
- **JavaScript**: Prefer the existing vanilla JavaScript module pattern in `public/js/`. Follow the bootstrapping approach in `public/js/app.js`, and keep feature behavior in the closest matching folder under `public/js/components/` or `public/js/features/`.
- **Styling**: Prefer Tailwind utilities and existing component styles from `public/css/tailwind.css` and `public/css/components/`. Reuse Flowbite-compatible patterns already present in the app.
- **Architecture**: Preserve the current event-driven frontend shape where relevant, including manager-style initialization, route-aware behavior, and progressive enhancement on top of server-rendered HTML.
- **Scope control**: Do not reach for React or Vue unless the user explicitly asks for a new isolated frontend technology experiment. The default path in this repository is Twig + Tailwind + vanilla JS.

## Verification

After implementing frontend changes in this repository:
1. Rebuild assets with `make app-build-assets`.
2. Confirm the generated change fits the existing Webpack/Tailwind pipeline defined by `webpack.config.js` and `public/css/tailwind.css`.
3. Check the relevant page in the browser and verify the UI works with the repo's existing modal, tabs, chart, or routing patterns where applicable.
4. If the change affects server-rendered behavior or controllers, run the relevant PHP tests or integration checks in addition to the asset build.

## Source Note

This skill is derived from Anthropic's `frontend-design` skill in `anthropics/claude-code`, adapted here for project-local use and compatibility with `.github/skills/`.