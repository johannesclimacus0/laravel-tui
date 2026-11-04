"use strict";

const $ = (id) => document.getElementById(id);
const dots = [
  [0, 3],
  [1, 4],
  [2, 5],
  [6, 7],
];
let storageKey = "laravel-tui.art-editor.v1";
let saving = false;
let arts = Object.create(null);
let original;
let state;
let selected = "h";
let tool = "brush";
let hover = null;
let stroke = null;
let undoStack = [];
let redoStack = [];
const editor = $("editor");
const preview = $("preview");
const status = (message) => {
  $("status").textContent = message;
};
const current = () => arts[state.active];
const snapshot = () => JSON.stringify(state);

function documentData() {
  return {
    version: 1,
    palette: state.palette,
    grids: Object.fromEntries(
      Object.entries(state.grids).filter(([name]) => state.enabled[name]),
    ),
    sources: Object.fromEntries(
      Object.values(arts).map((art) => [art.name, art.hash]),
    ),
    active: state.active,
  };
}

function saveDraft() {
  try {
    localStorage.setItem(storageKey, JSON.stringify(documentData()));
    status("draft saved locally — press save colors to apply");
  } catch {
    status("Draft could not be saved; export JSON");
  }
}

function commit(before) {
  if (before === snapshot()) return;
  undoStack.push(before);
  if (undoStack.length > 80) undoStack.shift();
  redoStack = [];
  saveDraft();
  render();
}

function restoreHistory(from, to) {
  finishStroke();
  if (!from.length) return;
  to.push(snapshot());
  state = JSON.parse(from.pop());
  if (!Object.hasOwn(state.palette, selected)) selected = ".";
  hover = null;
  saveDraft();
  render();
}

function choose(key) {
  selected = key;
  if (tool === "erase") setTool("brush");
  renderPalette();
}

function setTool(value) {
  tool = value;
  for (const button of document.querySelectorAll("[data-tool]")) {
    button.setAttribute("data-active", String(button.dataset.tool === tool));
  }
}

function renderPalette() {
  const container = $("palette");
  container.replaceChildren();
  for (const [key, hex] of [...Object.entries(state.palette), [".", null]]) {
    const row = document.createElement("div");
    row.className = "palette-row";
    const button = document.createElement("button");
    button.type = "button";
    button.setAttribute("data-active", String(selected === key));
    button.title = hex ? `Select ${key}: ${hex}` : "Default terminal color";
    const swatch = document.createElement("span");
    swatch.className = "swatch";
    swatch.style.background = hex ?? "#d8d0df";
    const code = document.createElement("code");
    code.textContent = `[ ${key} ]`;
    const label = document.createElement("small");
    label.textContent = hex ?? "default";
    button.append(swatch, code, label);
    button.addEventListener("click", () => choose(key));
    row.append(button);
    if (hex) {
      const picker = document.createElement("input");
      picker.type = "color";
      picker.value = hex;

      picker.addEventListener("change", () => {
        const before = snapshot();
        state.palette[key] = picker.value.toUpperCase();
        commit(before);
      });
      row.append(picker);
    }
    container.append(row);
  }
}

function prepareCanvas(canvas, width, height) {
  const ratio = window.devicePixelRatio || 1;
  canvas.width = Math.ceil(width * ratio);
  canvas.height = Math.ceil(height * ratio);
  canvas.style.width = width + "px";
  canvas.style.height = height + "px";
  const ctx = canvas.getContext("2d");
  ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
  ctx.fillStyle = $("background").value;
  ctx.fillRect(0, 0, width, height);
  return ctx;
}

function draw(canvas, size, showGrid, showRulers) {
  const art = current();
  const left = showRulers ? 32 : 8;
  const top = showRulers ? 26 : 8;
  const width = art.width * size + left + 8;
  const height = art.height * size * 2 + top + 8;
  const ctx = prepareCanvas(canvas, width, height);
  const unit = size / 2;
  const side = Math.max(1, unit * 0.7);
  for (let y = 0; y < art.height; y++) {
    for (let x = 0; x < art.width; x++) {
      const mask = Math.max(0, art.cells[y][x].codePointAt(0) - 0x2800);
      const key = state.grids[art.name][y][x];
      ctx.fillStyle = state.palette[key] ?? "#d8d0df";
      for (let dy = 0; dy < 4; dy++) {
        for (let dx = 0; dx < 2; dx++) {
          if (mask & (1 << dots[dy][dx])) {
            ctx.fillRect(
              left + x * size + dx * unit + (unit - side) / 2,
              top + y * size * 2 + dy * unit + (unit - side) / 2,
              side,
              side,
            );
          }
        }
      }
    }
  }
  if (showGrid) {
    ctx.strokeStyle = "#9694a536";
    ctx.lineWidth = 1;
    ctx.beginPath();
    for (let x = 0; x <= art.width; x++) {
      ctx.moveTo(left + x * size + 0.5, top);
      ctx.lineTo(left + x * size + 0.5, top + art.height * size * 2);
    }
    for (let y = 0; y <= art.height; y++) {
      ctx.moveTo(left, top + y * size * 2 + 0.5);
      ctx.lineTo(left + art.width * size, top + y * size * 2 + 0.5);
    }
    ctx.stroke();
  }
  if (showRulers) {
    ctx.font = "10px ui-monospace, monospace";
    ctx.fillStyle = "#aaa6b3";
    ctx.textAlign = "center";
    for (let x = 0; x < art.width; x++)
      ctx.fillText(String(x + 1), left + (x + 0.5) * size, 16);
    ctx.textAlign = "right";
    for (let y = 0; y < art.height; y++)
      ctx.fillText(String(y + 1), 25, top + (y + 0.5) * size * 2 + 3);
  }
  if (canvas === editor && hover) {
    ctx.strokeStyle = "#f4d3a5";
    ctx.lineWidth = 1.5;
    ctx.strokeRect(
      left + hover.x * size + 1,
      top + hover.y * size * 2 + 1,
      size - 2,
      size * 2 - 2,
    );
  }
}

function renderDrawing() {
  if (!state) return;
  const size = Number($("zoom").value);
  $("viewport").style.background = $("background").value;
  $("zoom-value").value = String(size);
  draw(editor, size, $("grid").checked, true);
  draw(preview, Math.min(6, 180 / current().width), false, false);
}

function render() {
  $("art").value = state.active;
  $("undo").disabled = !undoStack.length;
  $("redo").disabled = !redoStack.length;
  $("reset").disabled = false;
  renderPalette();
  renderDrawing();
}

function point(event) {
  const rectangle = editor.getBoundingClientRect();
  const size = Number($("zoom").value);
  const x = Math.floor((event.clientX - rectangle.left - 32) / size);
  const y = Math.floor((event.clientY - rectangle.top - 26) / (size * 2));
  return x >= 0 && y >= 0 && x < current().width && y < current().height
    ? { x, y }
    : null;
}

function empty(x, y) {
  return [" ", "⠀"].includes(current().cells[y][x]);
}

function paint(x, y, key) {
  if ($("skip-empty").checked && empty(x, y)) return;
  const name = state.active;
  const row = state.grids[name][y];
  if (row[x] === key) return;
  state.grids[name][y] = row.slice(0, x) + key + row.slice(x + 1);
  state.enabled[name] = true;
}

function fill(x, y, key) {
  const target = state.grids[state.active][y][x];
  if (target === key) return;
  const queue = [{ x, y }];
  while (queue.length) {
    const cell = queue.pop();
    if (
      cell.x < 0 ||
      cell.y < 0 ||
      cell.x >= current().width ||
      cell.y >= current().height
    )
      continue;
    if (
      state.grids[state.active][cell.y][cell.x] !== target ||
      ($("skip-empty").checked && empty(cell.x, cell.y))
    )
      continue;
    paint(cell.x, cell.y, key);
    queue.push(
      { x: cell.x - 1, y: cell.y },
      { x: cell.x + 1, y: cell.y },
      { x: cell.x, y: cell.y - 1 },
      { x: cell.x, y: cell.y + 1 },
    );
  }
}

function line(from, to, key) {
  let x = from.x;
  let y = from.y;
  const dx = Math.abs(to.x - x);
  const dy = -Math.abs(to.y - y);
  const sx = x < to.x ? 1 : -1;
  const sy = y < to.y ? 1 : -1;
  let error = dx + dy;
  while (true) {
    paint(x, y, key);
    if (x === to.x && y === to.y) break;
    const twice = error * 2;
    if (twice >= dy) {
      error += dy;
      x += sx;
    }
    if (twice <= dx) {
      error += dx;
      y += sy;
    }
  }
}

function finishStroke() {
  if (!stroke) return;
  const before = stroke.before;
  const pointer = stroke.pointer;
  stroke = null;
  if (editor.hasPointerCapture?.(pointer))
    editor.releasePointerCapture(pointer);
  commit(before);
}

editor.addEventListener("pointerdown", (event) => {
  if (!state || ![0, 2].includes(event.button)) return;
  const cell = point(event);
  if (!cell) return;
  event.preventDefault();
  editor.focus({ preventScroll: true });
  if (event.button === 2 || event.altKey || tool === "pick") {
    choose(state.grids[state.active][cell.y][cell.x]);
    return;
  }
  finishStroke();
  const before = snapshot();
  const key = tool === "erase" ? "." : selected;
  if (tool === "fill") {
    fill(cell.x, cell.y, key);
    commit(before);
    return;
  }
  stroke = { before, pointer: event.pointerId, last: cell, key };
  editor.setPointerCapture(event.pointerId);
  paint(cell.x, cell.y, key);
  renderDrawing();
});
editor.addEventListener("pointermove", (event) => {
  if (!state) return;
  hover = point(event);
  if (stroke && hover) {
    line(stroke.last ?? hover, hover, stroke.key);
    stroke.last = hover;
  } else if (stroke) stroke.last = null;
  renderDrawing();
});
editor.addEventListener("pointerleave", () => {
  hover = null;
  renderDrawing();
});
editor.addEventListener("pointerup", finishStroke);
editor.addEventListener("pointercancel", finishStroke);
editor.addEventListener("contextmenu", (event) => event.preventDefault());
window.addEventListener("blur", finishStroke);
window.addEventListener("resize", renderDrawing);

function validate(data) {
  if (
    !data ||
    data.version !== 1 ||
    !data.palette ||
    !data.grids ||
    Array.isArray(data.palette) ||
    Array.isArray(data.grids)
  )
    throw new Error("Invalid draft format");
  const palette = {};
  for (const [key, color] of Object.entries(data.palette)) {
    if (
      !/^[a-zA-Z0-9]$/.test(key) ||
      typeof color !== "string" ||
      !/^#[0-9a-fA-F]{6}$/.test(color)
    )
      throw new Error("Use a letter or digit and a #RRGGBB color");
    palette[key] = color.toUpperCase();
  }
  if (!Object.keys(palette).length || Object.keys(palette).length > 32)
    throw new Error("Palette must contain 1 to 32 colors");
  const next = {
    palette,
    grids: Object.create(null),
    enabled: Object.create(null),
    active: Object.hasOwn(arts, data.active)
      ? data.active
      : Object.keys(arts)[0],
  };
  for (const name of Object.keys(data.grids))
    if (!Object.hasOwn(arts, name)) throw new Error(`Unknown art: ${name}`);
  for (const art of Object.values(arts)) {
    const grid = Object.hasOwn(data.grids, art.name)
      ? data.grids[art.name]
      : undefined;
    if (data.sources?.[art.name] && data.sources[art.name] !== art.hash)
      throw new Error(
        `Source ${art.name} changed; color coordinates no longer match`,
      );
    if (grid !== undefined) {
      if (!Array.isArray(grid) || grid.length !== art.height)
        throw new Error(`${art.name}: expected ${art.height} rows`);
      grid.forEach((row, y) => {
        if (typeof row !== "string" || row.length !== art.width)
          throw new Error(
            `${art.name}, row ${y + 1}: expected ${art.width} cells`,
          );
        for (const key of row)
          if (key !== "." && !Object.hasOwn(palette, key))
            throw new Error(`${art.name}, row ${y + 1}: unknown color ${key}`);
      });
    }
    next.grids[art.name] = grid
      ? [...grid]
      : Array(art.height).fill(".".repeat(art.width));
    next.enabled[art.name] = grid !== undefined;
  }
  return next;
}

async function saveColors() {
  if (!state || saving) return;
  finishStroke();
  const body = JSON.stringify(documentData());
  saving = true;
  $("save-button").disabled = true;
  status("saving colors…");
  try {
    const response = await fetch(document.querySelector('meta[name="save-url"]').content, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]').content,
      },
      body,
    });
    const data = await response.json();
    if (!response.ok) {
      throw new Error(Object.values(data.errors ?? {}).flat().join("; ") || data.message || "Could not save colors");
    }
    if (body === JSON.stringify(documentData())) {
      try { localStorage.removeItem(storageKey); } catch {}
      status(data.message);
    } else {
      status("Colors saved; newer edits remain unsaved.");
    }
  } catch (error) {
    status(error.message);
  } finally {
    saving = false;
    $("save-button").disabled = false;
  }
}

function updateExport() {
  $("export-text").value = JSON.stringify(documentData(), null, 2);
  $("copy-status").textContent = "";
}

for (const button of document.querySelectorAll("[data-tool]"))
  button.addEventListener("click", () => setTool(button.dataset.tool));
$("art").addEventListener("change", () => {
  finishStroke();
  state.active = $("art").value;
  hover = null;
  render();
  saveDraft();
});
$("undo").addEventListener("click", () => restoreHistory(undoStack, redoStack));
$("redo").addEventListener("click", () => restoreHistory(redoStack, undoStack));
$("reset").addEventListener("click", () => {
  finishStroke();
  const before = snapshot();
  state.grids[state.active] = [...original.grids[state.active]];
  state.enabled[state.active] = original.enabled[state.active];
  commit(before);
});
for (const id of ["zoom", "grid", "background"])
  $(id).addEventListener("input", renderDrawing);
$("viewport").addEventListener(
  "wheel",
  (event) => {
    if (!event.ctrlKey) return;
    event.preventDefault();
    if (!state || event.deltaY === 0) return;
    finishStroke();
    const zoom = $("zoom");
    const before = Number(zoom.value);
    const size = Math.max(
      Number(zoom.min),
      Math.min(Number(zoom.max), before + (event.deltaY < 0 ? 1 : -1)),
    );
    if (size === before) return;
    const rect = editor.getBoundingClientRect();
    const x = (event.clientX - rect.left - 32) / before;
    const y = (event.clientY - rect.top - 26) / (before * 2);
    zoom.value = String(size);
    hover = null;
    renderDrawing();
    const next = editor.getBoundingClientRect();
    $("viewport").scrollLeft += next.left + 32 + x * size - event.clientX;
    $("viewport").scrollTop += next.top + 26 + y * size * 2 - event.clientY;
  },
  { passive: false },
);
$("add-color").addEventListener("submit", (event) => {
  event.preventDefault();
  if (!state) return;
  const key = $("new-key").value;
  if (
    !/^[a-zA-Z0-9]$/.test(key) ||
    Object.hasOwn(state.palette, key) ||
    Object.keys(state.palette).length >= 32
  ) {
    status("Use a new letter or digit; 32 colors max");
    return;
  }
  const before = snapshot();
  state.palette[key] = $("new-color").value.toUpperCase();
  selected = key;
  $("new-key").value = "";
  commit(before);
});
$("export-button").addEventListener("click", () => {
  if (state) {
    finishStroke();
    updateExport();
    $("export-dialog").showModal();
  }
});
$("copy").addEventListener("click", async () => {
  try {
    await navigator.clipboard.writeText($("export-text").value);
    $("copy-status").textContent = "copied";
  } catch {
    $("export-text").focus();
    $("export-text").select();
    $("copy-status").textContent = "Press Ctrl+C";
  }
});
$("download").addEventListener("click", () => {
  const url = URL.createObjectURL(
    new Blob([$("export-text").value], {
      type: "application/json",
    }),
  );
  const link = document.createElement("a");
  link.href = url;
  link.download = "colors.json";
  link.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
});
$("save-button").addEventListener("click", saveColors);
window.addEventListener("keydown", (event) => {
  const key = event.key.toLowerCase();
  const commandKey = { я: "z", н: "y", ы: "s" }[key] ?? key;
  if ((event.ctrlKey || event.metaKey) && commandKey === "s") {
    event.preventDefault();
    void saveColors();
    return;
  }
  if (
    !state ||
    event.target.matches("input, textarea, select") ||
    $("export-dialog").open
  )
    return;
  if (
    (event.ctrlKey || event.metaKey) &&
    ["z", "y"].includes(commandKey)
  ) {
    event.preventDefault();
    restoreHistory(
      event.shiftKey || commandKey === "y" ? redoStack : undoStack,
      event.shiftKey || commandKey === "y" ? undoStack : redoStack,
    );
  } else if (!event.ctrlKey && !event.metaKey && !event.altKey) {
    const shortcut = {
      b: "brush",
      и: "brush",
      g: "fill",
      п: "fill",
      i: "pick",
      ш: "pick",
      e: "erase",
      у: "erase",
    }[event.key.toLowerCase()];
    if (shortcut) setTool(shortcut);
  }
});

async function boot() {
  try {
    const response = await fetch(document.querySelector('meta[name="data-url"]').content, {
      cache: "no-store",
      headers: { "Accept": "application/json" },
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.message ?? "Could not load art");
    storageKey += ":" + data.workspace;
    for (const art of data.arts) {
      art.cells = art.lines.map((row) => [...row.padEnd(art.width, "⠀")]);
      arts[art.name] = art;
      const option = document.createElement("option");
      option.value = art.name;
      option.textContent = art.name;
      $("art").append(option);
    }
    if (!Object.keys(arts).length)
      throw new Error(
        data.errors?.join("; ") || "No .txt art found",
      );
    const initial = {
      version: 1,
      palette: data.palette,
      grids: Object.fromEntries(
        data.arts
          .filter((art) => art.grid !== null)
          .map((art) => [art.name, art.grid]),
      ),
      active: Object.keys(arts)[0],
    };
    state = validate(initial);
    original = JSON.parse(snapshot());
    let restored = false;
    try {
      const draft = localStorage.getItem(storageKey);
      if (draft) {
        const saved = JSON.parse(draft);
        if (saved.grids && typeof saved.grids === "object") {
          const renamed = (name) =>
            Object.hasOwn(arts, name)
              ? name
              : Object.values(arts).find(
                  (art) => art.hash === saved.sources?.[name],
                )?.name;
          const entries = Object.entries(saved.grids)
            .map(([name, grid]) => [renamed(name), grid])
            .filter(([name]) => name !== undefined);
          const sources = Object.fromEntries(
            Object.entries(saved.sources ?? {})
              .map(([name, hash]) => [renamed(name), hash])
              .filter(([name]) => name !== undefined),
          );
          saved.active = renamed(saved.active);
          saved.grids = Object.fromEntries(entries);
          saved.sources = sources;
        }
        state = validate(saved);
        restored = true;
      }
    } catch {
      status("Draft no longer matches the source; loaded saved colors");
    }
    selected = Object.hasOwn(state.palette, "h")
      ? "h"
      : Object.keys(state.palette)[0];
    $("art").disabled = false;
    $("save-button").disabled = false;
    render();
    status(
      data.errors?.length
        ? data.errors.join("; ")
        : restored
          ? "draft restored"
          : "loaded saved colors",
    );
  } catch (error) {
    status(error.message);
    $("export-button").disabled = true;
  }
}
void boot();
