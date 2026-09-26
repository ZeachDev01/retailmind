---
name: RetailMind
description: A precise, restrained operating system for the working stockroom.
colors:
  action-blue: "#2563eb"
  action-blue-deep: "#1d4ed8"
  control-navy: "#111827"
  control-navy-deep: "#0f172a"
  worksurface-grey: "#f4f6f9"
  surface-white: "#ffffff"
  surface-soft: "#f8fafc"
  ink: "#1f2937"
  muted: "#6b7280"
  line: "#e5e7eb"
  success: "#16a34a"
  warning: "#d97706"
  danger: "#dc2626"
  stockroom-green: "#0d3027"
  stockroom-green-mid: "#174c3c"
  signal-yellow: "#f2c94c"
  stockroom-paper: "#f2f0e8"
typography:
  display:
    fontFamily: '"Barlow Condensed", "Arial Narrow", sans-serif'
    fontSize: "clamp(4rem, 6.5vw, 7rem)"
    fontWeight: 700
    lineHeight: 0.84
    letterSpacing: "-0.035em"
  headline:
    fontFamily: '-apple-system, "Segoe UI", Roboto, Arial, sans-serif'
    fontSize: "clamp(1.4rem, 2vw, 1.8rem)"
    fontWeight: 700
    lineHeight: 1.2
  title:
    fontFamily: '-apple-system, "Segoe UI", Roboto, Arial, sans-serif'
    fontSize: "1.05rem"
    fontWeight: 700
    lineHeight: 1.3
  body:
    fontFamily: '-apple-system, "Segoe UI", Roboto, Arial, sans-serif'
    fontSize: "1rem"
    fontWeight: 400
    lineHeight: 1.5
  landing-body:
    fontFamily: '"IBM Plex Sans", "Segoe UI", sans-serif'
    fontSize: "1.05rem"
    fontWeight: 400
    lineHeight: 1.65
  label:
    fontFamily: '-apple-system, "Segoe UI", Roboto, Arial, sans-serif'
    fontSize: "0.72rem"
    fontWeight: 700
    lineHeight: 1.2
    letterSpacing: "0.12em"
rounded:
  square: "0"
  tag: "6px"
  nav: "8px"
  control: "10px"
  action: "12px"
  panel: "14px"
  dialog: "18px"
  pill: "999px"
spacing:
  xs: "0.25rem"
  sm: "0.5rem"
  md: "0.75rem"
  lg: "1rem"
  xl: "1.25rem"
  2xl: "1.5rem"
  3xl: "2rem"
components:
  button-primary:
    backgroundColor: "{colors.action-blue}"
    textColor: "{colors.surface-white}"
    rounded: "{rounded.control}"
    padding: "0.8rem 1rem"
    typography: "{typography.body}"
  button-primary-hover:
    backgroundColor: "{colors.action-blue-deep}"
    textColor: "{colors.surface-white}"
    rounded: "{rounded.control}"
    padding: "0.8rem 1rem"
  button-secondary:
    backgroundColor: "{colors.line}"
    textColor: "{colors.ink}"
    rounded: "{rounded.control}"
    padding: "0.8rem 1rem"
  input:
    backgroundColor: "{colors.surface-white}"
    textColor: "{colors.ink}"
    rounded: "{rounded.control}"
    padding: "0.75rem 0.9rem"
  panel:
    backgroundColor: "{colors.surface-white}"
    textColor: "{colors.ink}"
    rounded: "{rounded.panel}"
    padding: "1.2rem"
  tag-success:
    backgroundColor: "#dcfce7"
    textColor: "#166534"
    rounded: "{rounded.tag}"
    padding: "0.15rem 0.5rem"
  tag-warning:
    backgroundColor: "#fef3c7"
    textColor: "#92400e"
    rounded: "{rounded.tag}"
    padding: "0.15rem 0.5rem"
  landing-cta:
    backgroundColor: "{colors.signal-yellow}"
    textColor: "#17201d"
    rounded: "{rounded.square}"
    padding: "0.78rem 1.2rem"
    height: "50px"
---

# Design System: RetailMind

## Overview

**Creative North Star: "The Working Stockroom"**

RetailMind should feel like the well-run back room behind a dependable Store: every tool has a place, labels are unambiguous, and the next action is visible without spectacle. The operational workspace is precise, restrained, trustworthy, and administrative. It favors calm scanability over decorative expression because staff use it to complete consequential work throughout the day.

The public surface may show more of the physical retail world through condensed display type, documentary imagery, forest green, and Signal Yellow. Inside the product, that confidence becomes quieter: Control Navy establishes authority, Action Blue identifies interaction, and pale worksurfaces keep dense records readable. Both expressions belong to the same working environment rather than separate brands.

**Key Characteristics:**
- Role-aware operational hierarchy
- Restrained color with decisive state signals
- Compact but breathable information density
- Physical retail cues on public surfaces
- Calm, nontechnical presentation of system feedback

## Colors

The core workspace uses cool administrative neutrals and a single clear blue action voice; the public landing surface adds deep stockroom green, warm paper, and a limited yellow signal.

### Primary
- **Action Blue:** The standard interactive color for primary actions, links, focus treatments, active indicators, and operational emphasis.
- **Action Blue Deep:** The hover and pressed companion to Action Blue; it should reinforce interaction rather than create a second accent.

### Secondary
- **Stockroom Green:** The public-facing field color that connects RetailMind to the physical Store and stocked shelf.
- **Stockroom Green Mid:** A supporting green for public controls and lower-emphasis branded surfaces.

### Tertiary
- **Signal Yellow:** A scarce, high-contrast public accent for the strongest call to action, sequence markers, and industrial-rule details.

### Neutral
- **Control Navy:** The navigation shell and primary dark structural surface.
- **Control Navy Deep:** The deeper endpoint of the navigation gradient.
- **Worksurface Grey:** The application canvas behind operational panels.
- **Surface White:** The principal panel, form, table, and card surface.
- **Surface Soft:** A subtle secondary surface for quiet grouping and the translucent top bar.
- **Ink:** Default body copy and data text.
- **Muted:** Supporting labels, hints, and metadata.
- **Line:** Dividers, field borders, table rules, and card outlines.
- **Stockroom Paper:** The warmer public-page canvas.

Success, warning, and danger colors are semantic controls, not decorative accents. They identify real state and should retain readable foreground/background pairings.

### Named Rules

**The One Action Voice Rule.** Action Blue owns routine interactivity inside the product; do not introduce competing accent hues for equivalent actions.

**The Signal Means Something Rule.** Signal Yellow and semantic status colors are rare because they carry urgency, state, or sequence.

**The Two-Room Rule.** Forest green and warm paper belong to the expressive public room; blue, navy, and cool neutrals dominate the operational room.

## Typography

**Display Font:** Barlow Condensed (with Arial Narrow and sans-serif fallbacks)  
**Body Font:** System UI in the operational workspace; IBM Plex Sans on the public landing surface  
**Label Font:** System UI, with monospace reserved for technical codes and compact identifiers

**Character:** The operational type system is familiar and low-friction, allowing records and controls to lead. Barlow Condensed gives the public surface an assertive stockroom-poster voice, while IBM Plex Sans keeps its supporting copy practical rather than promotional.

### Hierarchy
- **Display** (700, `clamp(4rem, 6.5vw, 7rem)`, 0.84): Public hero statements only; uppercase with tight tracking.
- **Headline** (700, `clamp(1.4rem, 2vw, 1.8rem)`, 1.2): Workspace page titles and primary section orientation.
- **Title** (700, `1.05rem`, 1.3): Card, panel, and grouped-work headings.
- **Body** (400, `1rem`, 1.5): Operational instructions, data context, and general interface copy.
- **Landing Body** (400, `1.05rem`, 1.65): Public explanatory copy, kept to readable line lengths.
- **Label** (700, `0.72rem`, `0.12em` tracking): Uppercase navigation groups, compact metadata, and structural labels.

### Named Rules

**The Condensed-at-the-Door Rule.** Barlow Condensed creates public impact; it does not enter dense forms, tables, or routine workspace copy.

**The Data Before Drama Rule.** Operational typography may be firm, but never so oversized or stylized that it slows scanning.

## Layout

The application uses a fixed dark navigation rail, a slim fixed top bar, and a flexible content region. The expanded navigation width is 270px and the collapsed width is 78px; page content aligns to that shell rather than floating independently. Workspace headings pair the title and actions on one line when space permits, then stack cleanly on narrow screens.

Panels and metrics use responsive grids with practical minimum widths instead of rigid column counts. Common internal spacing clusters around 0.75rem, 1rem, and 1.2rem, with 1rem as the default relationship between neighboring controls. Dense tables remain horizontally scrollable rather than crushing columns into unreadable fragments.

The public landing surface is more compositional: its hero is a split field with copy and documentary imagery, followed by ordered capability and process sections. It collapses at 980px, 720px, and 480px. Operational screens primarily respond around 1180px, 760px, 700px, 640px, and 480px according to workflow density.

**The Working Radius Rule.** Give each task enough room to scan and act, but do not inflate operational whitespace for presentation alone.

## Elevation & Depth

RetailMind is quietly layered. Borders and tonal shifts establish most separation; low ambient shadows help white panels register against the Worksurface Grey without making every region appear draggable. Strong elevation is reserved for dialogs, drawers, account menus, and other surfaces that genuinely sit above the current task.

### Shadow Vocabulary
- **Quiet Panel:** `0 6px 18px rgba(15, 23, 42, 0.04)` for dashboard sections and metric cards.
- **Ambient Surface:** `0 8px 24px rgba(15, 23, 42, 0.07)` for menus and raised utility surfaces.
- **Dialog Lift:** `0 24px 64px rgba(15, 23, 42, 0.24)` for modal and drawer elevation.
- **Public Stamp:** `4px 4px 0` in Signal Yellow for selected public-surface emphasis where the existing industrial language calls for it.

### Named Rules

**The Border Before Shadow Rule.** A surface earns separation with structure first and elevation second.

**The Height Must Be Real Rule.** Strong shadows appear only when a surface overlays or interrupts another task layer.

## Shapes

Operational components use gently curved geometry: 8px navigation items, 10px controls, 12px actions, 14px panels, and 18px dialogs. Pills are reserved for compact counts and statuses. This measured radius progression communicates nesting and hierarchy without turning the interface soft or toy-like.

The public landing page deliberately uses square buttons and hard rules. This sharper silhouette evokes labels, shelving, signage, and printed stockroom material. Keep that contrast intentional: do not average the square public language and rounded operational language into an indistinct compromise.

## Components

Components are restrained and reassuring: states are visible, language is calm, and controls feel stable enough for repetitive Store work.

### Buttons
- **Shape:** Gently curved operational controls (10px); square public calls to action.
- **Primary:** Action Blue with white text, medium weight, and compact practical padding (`0.8rem 1rem`).
- **Hover / Focus:** Deepen the blue, lift by only 1px, and retain a clearly visible focus ring. Respect reduced-motion preferences.
- **Secondary:** Neutral Line fill with Ink text; it must remain visibly secondary without looking disabled.
- **Danger:** Use Danger red only for destructive or irreversible operations and pair it with explicit action copy.

### Chips
- **Style:** Compact status labels (6px radius) with tinted semantic backgrounds and dark readable text.
- **State:** Chips report state; they are not substitute buttons unless the interaction is unmistakable.

### Cards / Containers
- **Corner Style:** Layered by role: actions at 12px, panels and metric cards at 14px.
- **Background:** Surface White over Worksurface Grey; soft blue may appear inside quick actions.
- **Shadow Strategy:** Quiet Panel shadow with a visible Line border.
- **Internal Padding:** Usually 1rem to 1.2rem, tightened only for high-density records.

### Inputs / Fields
- **Style:** White field, Line stroke, 10px radius, and comfortable `0.75rem 0.9rem` padding.
- **Focus:** Action Blue border plus a soft three-pixel blue focus ring.
- **Error / Disabled:** Error uses Danger border and a restrained tinted ring; disabled controls reduce contrast and remove motion without becoming illegible.

### Navigation

The navigation rail is a Control Navy gradient with muted uppercase section labels, soft grey links, and an inset blue active indicator. Icons and labels remain aligned as the rail collapses. Hover states are tonal; they should not turn every link into a bright button. Mobile navigation becomes an explicit compact control rather than a squeezed desktop rail.

### Tables

Tables are operational records, not decorative card grids. Use clear column headers, quiet horizontal rules, stable alignment, and horizontal overflow on narrow screens. Actions should stay compact and semantically colored only where their meaning warrants it.

### Operator Alerts and Dialogs

Alerts favor easy words and calm hierarchy. Dialogs use the strongest depth token, an 18px outer radius, a clear title/action structure, and an obvious exit. Technical detail never becomes a visual motif in live Store use.

## Do's and Don'ts

### Do:
- **Do** use Action Blue consistently for ordinary interaction and focus.
- **Do** combine borders, tonal layers, and quiet shadows to separate operational regions.
- **Do** preserve the square, condensed, green-and-yellow public expression as a purposeful outer layer.
- **Do** use exact semantic colors for success, warning, and danger states.
- **Do** keep dense workflows scannable through alignment, grouping, and predictable control placement.
- **Do** honor reduced-motion preferences and visible keyboard focus.

### Don't:
- **Don't** introduce playful consumer-retail styling, novelty illustrations, bubbly controls, or candy-colored status systems.
- **Don't** introduce glossy, gradient-heavy generic SaaS styling, glass panels, ornamental glows, or decorative dashboard charts.
- **Don't** spread Signal Yellow across routine workspace controls; its rarity is part of its meaning.
- **Don't** use Barlow Condensed for tables, forms, long copy, or routine application labels.
- **Don't** use heavy elevation on static cards or flatten true overlays into the page.
- **Don't** expose raw technical failures through visually dominant alerts in the live Store.
