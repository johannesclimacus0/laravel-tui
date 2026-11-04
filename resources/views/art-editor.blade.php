<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Laravel TUI · Miku colors</title>
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <meta name="data-url" content="{{ route('laravel-tui.editor.data') }}" />
    <meta name="save-url" content="{{ route('laravel-tui.editor.save') }}" />
    <link rel="stylesheet" href="{{ route('laravel-tui.editor.asset', ['file' => 'editor.css']) }}" />
    <script src="{{ route('laravel-tui.editor.asset', ['file' => 'editor.js']) }}" defer></script>
  </head>
  <body>
    <main>
      <aside class="left-panel">
        <label for="art">art</label>
        <div class="art-controls">
          <select id="art" disabled></select>
        </div>
        <section>
          <h2>tools</h2>
          <div class="tool-grid">
            <button data-tool="brush" data-active="true">
              [ brush ] <kbd>B</kbd>
            </button>
            <button data-tool="fill" data-active="false">
              [ fill ] <kbd>G</kbd>
            </button>
            <button data-tool="pick" data-active="false">
              [ pick ] <kbd>I</kbd>
            </button>
            <button data-tool="erase" data-active="false">
              [ erase ] <kbd>E</kbd>
            </button>
          </div>
          <div class="brush-option">
            <label
              ><input id="skip-empty" type="checkbox" checked /> skip
              empty</label
            >
          </div>
          <div class="history">
            <button id="undo" disabled title="Undo · Ctrl+Z">&lt;--</button>
            <button id="redo" disabled title="Redo · Ctrl+Shift+Z">
              --&gt;
            </button>
          </div>
        </section>
        <section class="options">
          <label><input id="grid" type="checkbox" checked /> grid</label>
          <label for="zoom">cell size <output id="zoom-value">16</output></label>
          <input id="zoom" type="range" min="8" max="30" value="16" />
          <label for="background">background <input id="background" type="color" value="#20212c"/></label>
        </section>
        <div class="panel-bottom">
          <span id="status"></span>
          <button id="reset" disabled>[ reset ]</button>
        </div>
      </aside>
      <section class="workspace">
        <div id="viewport">
          <canvas id="editor" tabindex="0"></canvas>
        </div>
      </section>
      <aside class="right-panel">
        <section>
          <h2>palette</h2>
          <div id="palette"></div>
          <form id="add-color">
            <input id="new-key" maxlength="1" pattern="[A-Za-z0-9]" placeholder="key" required/>
            <input id="new-color" type="color" value="#ffffff" />
            <button type="submit" title="Add color">[+]</button>
          </form>
        </section>
        <section>
          <h2>preview</h2>
          <canvas id="preview"></canvas>
        </section>
        <div class="panel-bottom actions">
          <button id="save-button" disabled>[ save colors ]</button>
          <button id="export-button">[ export ]</button>
        </div>
      </aside>
    </main>
    <dialog id="export-dialog">
      <form method="dialog" class="dialog-heading">
        <h2>export / json</h2>
        <button>[ close ]</button>
      </form>
      <textarea id="export-text" spellcheck="false" readonly></textarea>
      <div class="actions">
        <button id="copy">[ copy ]</button>
        <button id="download">[ download ]</button>
        <span id="copy-status"></span>
      </div>
    </dialog>
  </body>
</html>
