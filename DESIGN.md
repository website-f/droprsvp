---
version: alpha
name: Meetup
description: >-
  Meetup is the people platform where interests become friendships. A community-driven social discovery engine that
  connects people through shared passions, local events, and authentic human connection.
logo:
  src: https://secure.meetupstatic.com/next/images/general/m_redesign_152x152.png
colors:
  surface: '#fcfcfd'
  surface-dim: '#f5f5f6'
  surface-bright: '#ffffff'
  surface-container-lowest: '#f9f9fa'
  surface-container-low: '#f2f2f3'
  surface-container: '#ececed'
  surface-container-high: '#e6e6e7'
  surface-container-highest: '#d9d9da'
  on-surface: '#000000'
  on-surface-variant: '#3f3f3f'
  inverse-surface: '#121215'
  inverse-on-surface: '#f5f5f6'
  outline: '#707070'
  outline-variant: '#8c8f94'
  surface-tint: '#e32359'
  primary: '#e32359'
  on-primary: '#ffffff'
  primary-container: '#fce4ed'
  on-primary-container: '#9f2042'
  inverse-primary: '#ff6b9d'
  secondary: '#00798a'
  on-secondary: '#ffffff'
  secondary-container: '#b3e5fc'
  on-secondary-container: '#004d5c'
  tertiary: '#00a6bf'
  on-tertiary: '#ffffff'
  tertiary-container: '#b3e5fc'
  on-tertiary-container: '#005a6b'
  error: '#c80000'
  on-error: '#ffffff'
  error-container: '#ffb4ab'
  on-error-container: '#93000a'
  primary-fixed: '#fce4ed'
  primary-fixed-dim: '#f8bbd0'
  on-primary-fixed: '#9f2042'
  on-primary-fixed-variant: '#c80000'
  secondary-fixed: '#b3e5fc'
  secondary-fixed-dim: '#80deea'
  on-secondary-fixed: '#004d5c'
  on-secondary-fixed-variant: '#00798a'
  tertiary-fixed: '#b3e5fc'
  tertiary-fixed-dim: '#80deea'
  on-tertiary-fixed: '#005a6b'
  on-tertiary-fixed-variant: '#00798a'
  background: '#fcfcfd'
  on-background: '#000000'
  surface-variant: '#ececed'
typography:
  display:
    fontFamily: NeuSans, Inter, system-ui, sans-serif
    fontSize: 60px
    fontWeight: '700'
    lineHeight: 68px
    letterSpacing: '-0.04em'
  headline-lg:
    fontFamily: NeuSans, Inter, system-ui, sans-serif
    fontSize: 40px
    fontWeight: '700'
    lineHeight: 48px
    letterSpacing: '-0.02em'
  headline-md:
    fontFamily: NeuSans, Inter, system-ui, sans-serif
    fontSize: 28px
    fontWeight: '600'
    lineHeight: 36px
    letterSpacing: '-0.01em'
  title-lg:
    fontFamily: NeuSans, Inter, system-ui, sans-serif
    fontSize: 20px
    fontWeight: '600'
    lineHeight: 28px
    letterSpacing: 0em
  body-lg:
    fontFamily: NeuSans, system-ui, Helvetica, Arial, sans-serif
    fontSize: 18px
    fontWeight: '400'
    lineHeight: 28px
    letterSpacing: 0em
  body-md:
    fontFamily: NeuSans, system-ui, Helvetica, Arial, sans-serif
    fontSize: 16px
    fontWeight: '400'
    lineHeight: 24px
    letterSpacing: 0em
  label-md:
    fontFamily: NeuSans, system-ui, Helvetica, Arial, sans-serif
    fontSize: 14px
    fontWeight: '600'
    lineHeight: 20px
    letterSpacing: 0.01em
  label-sm:
    fontFamily: NeuSans, system-ui, Helvetica, Arial, sans-serif
    fontSize: 12px
    fontWeight: '500'
    lineHeight: 16px
    letterSpacing: 0.02em
rounded:
  sm: 0.25rem
  DEFAULT: 0.5rem
  md: 0.75rem
  lg: 1rem
  xl: 1.5rem
  full: 9999px
spacing:
  unit: 8px
  xs: 4px
  sm: 12px
  md: 24px
  lg: 40px
  xl: 64px
  gutter: 24px
  container-max: 1280px
elevation:
  sm: 0 1px 2px rgba(0, 0, 0, 0.06)
  md: 0 4px 12px rgba(0, 0, 0, 0.1)
  lg: 0 16px 40px rgba(0, 0, 0, 0.12)
layout:
  containerMaxWidth: 1280px
  gridColumns: 12
components:
  button-primary:
    backgroundColor: '{colors.primary}'
    textColor: '{colors.on-primary}'
    typography: '{typography.label-md}'
    rounded: '{rounded.full}'
    padding: 12px 32px
    height: 48px
    boxShadow: '{elevation.md}'
    transition: background-color 200ms ease, box-shadow 200ms ease
  button-primary-hover:
    backgroundColor: '#c80000'
    boxShadow: '{elevation.lg}'
  button-primary-active:
    backgroundColor: '#9f2042'
    transform: scale(0.98)
  button-secondary:
    backgroundColor: transparent
    textColor: '{colors.primary}'
    typography: '{typography.label-md}'
    rounded: '{rounded.full}'
    padding: 12px 32px
    height: 48px
    border: 2px solid {colors.primary}
    transition: background-color 200ms ease, color 200ms ease
  button-secondary-hover:
    backgroundColor: '{colors.primary-container}'
    textColor: '{colors.on-primary-container}'
  button-ghost:
    backgroundColor: rgba(18, 18, 21, 0.4)
    textColor: '{colors.on-surface}'
    typography: '{typography.label-md}'
    rounded: '{rounded.full}'
    padding: 6px 16px
    height: 40px
    border: none
    transition: background-color 150ms ease
  button-ghost-hover:
    backgroundColor: rgba(18, 18, 21, 0.6)
  card:
    backgroundColor: '{colors.surface-container-lowest}'
    rounded: '{rounded.lg}'
    padding: '{spacing.md}'
    boxShadow: '{elevation.sm}'
    border: 1px solid {colors.surface-container-high}
    transition: box-shadow 200ms ease, background-color 200ms ease
  card-hover:
    backgroundColor: '{colors.surface-container-low}'
    boxShadow: '{elevation.md}'
  input-field:
    backgroundColor: '{colors.surface-bright}'
    textColor: '{colors.on-surface}'
    typography: '{typography.body-md}'
    rounded: '{rounded.DEFAULT}'
    padding: 12px 16px
    height: 44px
    border: 1px solid {colors.outline-variant}
    transition: border-color 200ms ease, box-shadow 200ms ease
  input-field-focus:
    borderColor: '{colors.primary}'
    boxShadow: 0 0 0 3px rgba(227, 35, 89, 0.1)
  list-item:
    backgroundColor: transparent
    rounded: '{rounded.md}'
    padding: '{spacing.sm}'
    transition: background-color 150ms ease
  list-item-hover:
    backgroundColor: '{colors.surface-container-high}'
    textColor: '{colors.primary}'
  badge:
    backgroundColor: '{colors.tertiary-container}'
    textColor: '{colors.on-tertiary-container}'
    typography: '{typography.label-sm}'
    rounded: '{rounded.full}'
    padding: 4px 12px
    height: 24px
    display: inline-flex
    alignItems: center
  badge-error:
    backgroundColor: '{colors.error-container}'
    textColor: '{colors.on-error-container}'
  search-input:
    backgroundColor: '{colors.surface-bright}'
    textColor: '{colors.on-surface}'
    typography: '{typography.body-md}'
    rounded: '{rounded.DEFAULT}'
    padding: 12px 16px
    height: 44px
    border: 1px solid {colors.outline-variant}
    boxShadow: '{elevation.sm}'
  search-input-focus:
    borderColor: '{colors.primary}'
    boxShadow: 0 0 0 3px rgba(227, 35, 89, 0.1)
---

## Overview

Meetup is a community-driven social discovery platform that transforms shared interests into authentic human connections. The design system embodies 'Warm Minimalism'—a philosophy that combines clean, approachable interfaces with vibrant accent colors and playful, human-centered illustrations. The brand personality is optimistic, inclusive, and energetic: it celebrates the joy of finding your people. The UI prioritizes clarity and accessibility, using generous whitespace (40px–64px section separation), a restrained color palette anchored by a signature hot pink primary (#e32359), and typography that feels both modern and inviting. Voice: conversational, encouraging, never corporate. Example sentence: 'Find your people—thousands of groups are meeting right now, and your next best friend is probably already signed up.'

## Colors

The color system is built on a light, airy foundation (surface #fcfcfd) paired with a bold, energetic primary accent. Primary (#e32359) is the signature hot pink used exclusively on call-to-action buttons, active states, and key interactive elements—it commands attention without overwhelming. Secondary (#00798a, a teal) and tertiary (#00a6bf, a cyan) provide supporting accents for status indicators, badges, and secondary actions. The surface stack ranges from near-white (#fcfcfd) to light gray (#d9d9da), creating subtle visual hierarchy without harsh contrast. On-surface text is pure black (#000000) for maximum readability at 16px body size. Error states use a deep red (#c80000) for critical warnings. The outline color (#707070) is used for borders and dividers, maintaining visual separati

## Typography

The type system uses NeuSans (custom) as the primary typeface, paired with Inter as a fallback for headlines, creating a modern yet approachable voice. Display (60px, 700 weight, -0.04em tracking) is reserved for hero statements and brand moments; headline-lg (40px, 700 weight) anchors major sections; headline-md (28px, 600 weight) breaks up content hierarchically. Body text uses 16px at 400 weight with 24px line-height for comfortable reading; smaller body-md (14px) is used in dense contexts like event cards. All labels use 600–700 weight to signal interactivity. Letter-spacing is tight on display and headlines (-0.04em to -0.01em) to create visual impact, while body text uses 0em spacing for natural readability. Apply text-shadow: 0 2px 4px rgba(0, 0, 0, 0.08) on small labels (label-sm)

## Layout

The layout uses a 12-column fluid grid with a max-width of 1280px, centered on the viewport. Section separation follows the spacing scale: lg (40px) for major section breaks, md (24px) for subsection spacing, and sm (12px) for component-level padding. The hero section uses asymmetrical composition with illustration clusters on left and right, centering the headline and CTA vertically. Container padding is consistently 24px (gutter) on desktop, reducing to 16px on tablet and 12px on mobile. Event cards are arranged in a 4-column grid on desktop, 2 columns on tablet, and 1 column on mobile, with 24px gap between cards. The search bar at the top uses a fixed height of 44px with 12px padding, allowing for quick scanning. Whitespace is generous—the hero section has 64px top/bottom padding to cr

## Elevation & Depth

Elevation is achieved through subtle shadows and layering rather than depth gradients. Level 1 (Base): no shadow; surface is flat at #fcfcfd. Level 2 (Cards & Inputs): box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1), with a 1px border at rgba(0, 0, 0, 0.08) to define edges. Level 3 (Modals & Dropdowns): box-shadow: 0 16px 40px rgba(0, 0, 0, 0.12), creating clear separation from the background. Hover states on interactive elements elevate from Level 2 to Level 3, signaling interactivity. Focus states add a 3px outline at rgba(227, 35, 89, 0.1) (primary with 10% opacity) to indicate keyboard navigatio

## Shapes

The shape philosophy is 'Soft-Technical'—combining rounded corners with geometric precision to feel both approachable and modern. Buttons use full border-radius (9999px) to create pill-shaped affordances that feel friendly and clickable; this applies to primary, secondary, and ghost buttons uniformly. Cards and containers use lg (1rem / 16px) border-radius for a subtle roundness that softens the interface without feeling overly playful. Input fields use DEFAULT (0.5rem / 8px) for a more restrained, professional appearance. Badges and status indicators use full (9999px) to emphasize their role

## Components

### Action Elements
Buttons are the primary interaction pattern. Primary buttons (button-primary) use the signature hot pink (#e32359) background with white text, 48px height, 12px–32px padding, and full border-radius. On hover, the background darkens to #c80000 and the shadow elevates from md to lg (0 16px 40px rgba(0, 0, 0, 0.12)). On active/click, the background shifts to #9f2042 and applies a subtle scale(0.98) transform to provide tactile feedback. Secondary buttons (button-secondary) use a transparent background with a 2px primary-colored border and the same text color; on hover, the background fills with primary-container (#fce4ed) and text shifts to on-primary-container (#9f2042). Ghost buttons (button-ghost) use a semi-transparent dark overlay (rgba(18, 18, 21, 0.4)) with 40px hei

## Do's and Don'ts

**Do**
- Do use the primary hot pink (#e32359) exclusively on CTAs and active states—it's the brand's signature accent and should never be diluted or overused on secondary elements.
- Do maintain generous whitespace (40px–64px) between major sections to create visual breathing room and guide the eye through content hierarchy.
- Do apply the full border-radius (9999px) consistently on all buttons to create a cohesive, friendly interaction pattern that users instantly recognize as clickable.
- Do use NeuSans as the primary typeface for headlines and body text; it's the brand's custom font and carries the personality—avoid substituting with generic system fonts.
- Do apply the md shadow (0 4px 12px rgba(0, 0, 0, 0.1)) on hover states to signal interactivity and create a subtle lift effect without overwhelming the interface.

**Don't**
- Don't use the primary color on non-interactive elements like backgrounds or decorative text—reserve it for buttons, links, and active states only.
- Don't reduce border-radius on buttons below full (9999px); the pill shape is a core brand identifier and changing it breaks visual consistency.
- Don't apply shadows darker than lg (0 16px 40px rgba(0, 0, 0, 0.12)) on any element; Meetup's aesthetic is light and approachable, not heavy or dramatic.
- Don't mix typefaces within a single section; stick to NeuSans for headlines and body text, using weight and size to create hierarchy instead.
- Don't use the secondary or tertiary colors as primary CTAs—they're supporting accents for badges, status indicators, and secondary actions only.
