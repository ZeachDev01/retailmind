---
name: RetailMind
description: A precise, restrained operating system for one connected Store.
colors:
  action-blue: "#2563eb"
  action-blue-deep: "#1d4ed8"
  control-navy: "#111827"
  control-navy-deep: "#0f172a"
  entrance-navy: "#0f1f3d"
  entrance-navy-deep: "#0b1427"
  worksurface-grey: "#f4f6f9"
  surface-white: "#ffffff"
  surface-soft: "#f8fafc"
  ink: "#1f2937"
  muted: "#6b7280"
  entrance-muted: "#667085"
  line: "#e5e7eb"
  entrance-line: "#dce2ea"
  success: "#16a34a"
  warning: "#d97706"
  danger: "#dc2626"
typography:
  display:
    fontFamily: '-apple-system, "Segoe UI", Roboto, Arial, sans-serif'
    fontSize: "clamp(3.1rem, 5vw, 4.7rem)"
    fontWeight: 760
    lineHeight: 0.96
    letterSpacing: "-0.04em"
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
  label:
    fontFamily: '-apple-system, "Segoe UI", Roboto, Arial, sans-serif'
    fontSize: "0.72rem"
    fontWeight: 700
    lineHeight: 1.2
    letterSpacing: "0.12em"
rounded:
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
---

# Design System: RetailMind

## Overview

**Creative North Star: "The Working Stockroom"**

RetailMind should feel like a well-run Store where every tool has a place, labels are unambiguous, and the next action is visible without spectacle. The system is precise, restrained, trustworthy, and administrative. It favors calm scanability over decorative expression because staff use it to complete consequential work throughout the day.

The public entrance and role workspaces are one operational world. Control Navy and its darker entrance variants establish authority, Action Blue identifies interaction, and cool grey worksurfaces with white panels keep records readable. Documentary retail imagery may add real-world context, but the surrounding interface keeps the same system-UI typography, rounded controls, quiet depth, and compact status language staff encounter after login.

**Key Characteristics:**
- Role-aware operational hierarchy
- Restrained color with decisive state signals
- Compact but breathable information density
- One visual language from public entrance to role workspace
- Calm, nontechnical presentation of system feedback

## Colors

RetailMind uses cool administrative neutrals, disciplined navy structure, and a single clear blue action voice across both the public entrance and authenticated workspaces.

### Primary
- **Action Blue:** The standard interactive color for primary actions, links, focus treatments, active indicators, and operational emphasis.
- **Action Blue Deep:** The hover and pressed companion to Action Blue; it should reinforce interaction rather than create a second accent.

### Neutral
- **Control Navy:** The navigation shell and primary dark structural surface.
- **Control Navy Deep:** The deeper endpoint of the navigation gradient.
- **Entrance Navy:** The public header and heading tone; it is a slightly cooler extension of Control Navy, not a separate brand color.
- **Entrance Navy Deep:** The public brand rail, workflow section, footer, and login-aside surface.
- **Worksurface Grey:** The application canvas behind operational panels.
- **Surface White:** The principal panel, form, table, and card surface.
- **Surface Soft:** A subtle secondary surface for quiet grouping and the translucent top bar.
- **Ink:** Default body copy and data text.
- **Muted:** Supporting labels, hints, and metadata.
- **Entrance Muted:** Supporting public copy where slightly stronger contrast is needed on the cool canvas.
- **Line:** Dividers, field borders, table rules, and card outlines.
- **Entrance Line:** The slightly cooler public border tone used for app-style panels and process rails.

Success, warning, and danger colors are semantic controls, not decorative accents. They identify real state and should retain readable foreground/background pairings.

### Named Rules

**The One Action Voice Rule.** Action Blue owns routine interactivity inside the product; do not introduce competing accent hues for equivalent actions.

**The State Means Something Rule.** Semantic status colors are reserved for actual state, risk, or required attention rather than decoration.

**The One Operational World Rule.** The public entrance uses the same navy, blue, cool-neutral, and semantic logic as the role workspaces; do not invent a campaign palette at the door.

## Typography

**Display Font:** System UI (with Segoe UI, Roboto, Arial, and sans-serif fallbacks)
**Body Font:** System UI across the public entrance and operational workspaces
**Label Font:** System UI, with monospace reserved for technical codes and compact identifiers

**Character:** The type system is familiar, direct, and low-friction, allowing records, controls, and Store outcomes to lead. Weight, scale, and compact labels create hierarchy without asking staff to learn a separate display voice at the public entrance.

### Hierarchy
- **Display** (760, `clamp(3.1rem, 5vw, 4.7rem)`, 0.96): Public hero statements in sentence case with tight tracking and a controlled line length.
- **Headline** (700, `clamp(1.4rem, 2vw, 1.8rem)`, 1.2): Workspace page titles and primary section orientation.
- **Title** (700, `1.05rem`, 1.3): Card, panel, and grouped-work headings.
- **Body** (400, `1rem`, 1.5): Operational instructions, data context, and general interface copy.
- **Label** (700, `0.72rem`, `0.12em` tracking): Uppercase navigation groups, compact metadata, and structural labels.

### Named Rules

**The Same Voice at the Door Rule.** Public headings may scale up, but they retain the same system-UI voice, sentence case, and operational directness as authenticated screens.

**The Data Before Drama Rule.** Operational typography may be firm, but never so oversized or stylized that it slows scanning.

## Layout

The application uses a fixed dark navigation rail, a slim fixed top bar, and a flexible content region. The expanded navigation width is 270px and the collapsed width is 78px; page content aligns to that shell rather than floating independently. Workspace headings pair the title and actions on one line when space permits, then stack cleanly on narrow screens.

Panels and metrics use responsive grids with practical minimum widths instead of rigid column counts. Common internal spacing clusters around 0.75rem, 1rem, and 1.2rem, with 1rem as the default relationship between neighboring controls. Dense tables remain horizontally scrollable rather than crushing columns into unreadable fragments.

The public entrance adapts the workspace grammar to persuasion: an app-style header leads into a two-column hero with a bordered inventory panel and four-stage process rail, followed by ordered capability and workflow sections. It stacks at 900px, tightens at 640px, and receives a final compact adjustment at 390px; the hero starts reducing its split at 1040px. Operational screens primarily respond around 1180px, 760px, 700px, 640px, and 480px according to workflow density.

**The Working Radius Rule.** Give each task enough room to scan and act, but do not inflate operational whitespace for presentation alone.

## Elevation & Depth

RetailMind is quietly layered. Borders and tonal shifts establish most separation; low ambient shadows help white panels register against the Worksurface Grey without making every region appear draggable. Strong elevation is reserved for dialogs, drawers, account menus, and other surfaces that genuinely sit above the current task.

### Shadow Vocabulary
- **Quiet Panel:** `0 6px 18px rgba(15, 23, 42, 0.04)` for dashboard sections and metric cards.
- **Ambient Surface:** `0 8px 24px rgba(15, 23, 42, 0.07)` for menus and raised utility surfaces.
- **Dialog Lift:** `0 24px 64px rgba(15, 23, 42, 0.24)` for modal and drawer elevation.

### Named Rules

**The Border Before Shadow Rule.** A surface earns separation with structure first and elevation second.

**The Height Must Be Real Rule.** Strong shadows appear only when a surface overlays or interrupts another task layer.

## Shapes

Components use gently curved geometry across the full product: 8px navigation items, 10px controls and primary calls to action, 12px action containers, 14px panels, and 18px dialogs. Pills are reserved for compact counts and statuses. This measured radius progression communicates nesting and hierarchy without turning the interface soft or toy-like; the public entrance follows it rather than introducing a second silhouette.

## Components

Components are restrained and reassuring: states are visible, language is calm, and controls feel stable enough for repetitive Store work.

### Buttons
- **Shape:** Gently curved controls and public calls to action (10px).
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

The public entrance uses a white app-style header with a dark navy brand rail and a blue Staff login action. Its link and focus states follow the same action hierarchy as the role workspaces.

### Tables

Tables are operational records, not decorative card grids. Use clear column headers, quiet horizontal rules, stable alignment, and horizontal overflow on narrow screens. Actions should stay compact and semantically colored only where their meaning warrants it.

### Operator Alerts and Dialogs

Alerts favor easy words and calm hierarchy. Dialogs use the strongest depth token, an 18px outer radius, a clear title/action structure, and an obvious exit. Technical detail never becomes a visual motif in live Store use.

## Do's and Don'ts

### Do:
- **Do** use Action Blue consistently for ordinary interaction and focus.
- **Do** combine borders, tonal layers, and quiet shadows to separate operational regions.
- **Do** carry the navy, blue, cool-neutral, system-UI workspace language through the public entrance.
- **Do** use exact semantic colors for success, warning, and danger states.
- **Do** keep dense workflows scannable through alignment, grouping, and predictable control placement.
- **Do** honor reduced-motion preferences and visible keyboard focus.

### Don't:
- **Don't** introduce playful consumer-retail styling, novelty illustrations, bubbly controls, or candy-colored status systems.
- **Don't** introduce glossy, gradient-heavy generic SaaS styling, glass panels, ornamental glows, or decorative dashboard charts.
- **Don't** give the public entrance a separate campaign palette; it shares the application's navy, blue, cool-neutral, and semantic system.
- **Don't** create public-only typography or shape tokens; the entrance uses the same system-UI voice and measured corner progression as the application.
- **Don't** use heavy elevation on static cards or flatten true overlays into the page.
- **Don't** expose raw technical failures through visually dominant alerts in the live Store.
