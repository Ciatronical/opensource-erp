import { css } from 'lit';

/**
 * Frameworkfreies Grundaussehen für die shop-*-Rollenklassen.
 *
 * Diese Schicht wird VOR dem Theme-Stylesheet in den ShadowRoot gelegt.
 * Damit ergibt sich die Kaskade:
 *
 *   1. defaults      (hier)            — trägt die Komponente ohne Framework
 *   2. Theme         (Bootstrap/Pure/eigenes) — überschreibt, was es kennt
 *   3. Komponenten-CSS (static styles) — Layout und Struktur des Widgets
 *
 * Bootstrap kennt `.shop-input` nicht, überschreibt aber `.form-control`,
 * das die Komponente parallel vergibt — bei gleicher Spezifität gewinnt die
 * spätere Schicht. Ohne Framework bleibt dieses CSS wirksam.
 *
 * Alle Werte hängen an CSS-Custom-Properties: die erben durch die
 * Shadow-Grenze, ein Shop kann sie also von außen setzen, ohne irgendetwas
 * in den ShadowRoot zu injizieren.
 */
export const defaultStyles = css`
  .shop-label {
    display: block;
    margin-bottom: 0.25rem;
    font-weight: var(--shop-label-weight, 400);
  }

  .shop-input,
  .shop-select {
    display: block;
    width: 100%;
    padding: var(--shop-input-padding, 0.375rem 0.75rem);
    border: var(--shop-border-width, 1px) solid var(--shop-border-color, #ced4da);
    border-radius: var(--shop-radius, 0.375rem);
    font: inherit;
    color: inherit;
    background: var(--shop-input-bg, #fff);
  }

  .shop-input:focus-visible,
  .shop-select:focus-visible {
    outline: 2px solid var(--shop-accent, #0d6efd);
    outline-offset: 1px;
  }

  .shop-button,
  .shop-button-primary,
  .shop-button-secondary {
    display: inline-block;
    padding: var(--shop-button-padding, 0.375rem 0.75rem);
    border: var(--shop-border-width, 1px) solid transparent;
    border-radius: var(--shop-radius, 0.375rem);
    font: inherit;
    cursor: pointer;
    background: var(--shop-button-bg, #6c757d);
    color: var(--shop-button-color, #fff);
  }

  .shop-button-primary {
    background: var(--shop-accent, #0d6efd);
  }

  .shop-button:disabled,
  .shop-button-primary:disabled,
  .shop-button-secondary:disabled {
    opacity: 0.65;
    cursor: default;
  }

  .shop-alert-error,
  .shop-alert-success,
  .shop-alert-info {
    padding: 0.75rem 1rem;
    border: var(--shop-border-width, 1px) solid transparent;
    border-radius: var(--shop-radius, 0.375rem);
  }

  .shop-alert-error {
    color: var(--shop-error-color, #842029);
    background: var(--shop-error-bg, #f8d7da);
    border-color: var(--shop-error-border, #f5c2c7);
  }

  .shop-alert-info {
    color: var(--shop-info-color, #084298);
    background: var(--shop-info-bg, #cfe2ff);
    border-color: var(--shop-info-border, #b6d4fe);
  }

  .shop-alert-success {
    color: var(--shop-success-color, #0f5132);
    background: var(--shop-success-bg, #d1e7dd);
    border-color: var(--shop-success-border, #badbcc);
  }
`;
