/**
 * Square photo cropper for the membership card.
 *
 * Written rather than pulled in as a library: the whole job is one canvas, a drag, and a
 * zoom, and a dependency for that would be larger than the code and another thing to keep
 * patched. It exports a fixed 600x600 JPEG, so what a member sees in the circle is exactly
 * what lands on the card, and the file that reaches the server is predictable in size
 * whatever came off the phone.
 *
 * Usage: ResokCropper.open(file).then(blob => ...); resolves null if the member cancels.
 */
window.ResokCropper = (function () {
  const OUTPUT = 600;         // exported square, comfortably above the card's 168px slot
  const VIEW = 320;           // on-screen editor size
  const QUALITY = 0.9;

  let overlay = null;

  function css() {
    if (document.getElementById("cropperStyles")) return;
    const style = document.createElement("style");
    style.id = "cropperStyles";
    style.textContent = `
      .crop-back{position:fixed;inset:0;z-index:4000;display:grid;place-items:center;padding:20px;
        background:rgba(10,22,38,.62);backdrop-filter:blur(2px)}
      .crop-box{width:min(400px,100%);background:#fff;border-radius:12px;padding:24px;text-align:center;
        box-shadow:0 24px 60px rgba(15,23,42,.3);font-family:"Segoe UI",Tahoma,sans-serif;color:#253141}
      .crop-box h2{font-size:17px;margin-bottom:4px}
      .crop-box p{font-size:13px;color:#667085;margin-bottom:16px}
      .crop-stage{position:relative;width:${VIEW}px;height:${VIEW}px;margin:0 auto;border-radius:10px;
        overflow:hidden;background:#0f172a;cursor:grab;touch-action:none}
      .crop-stage.dragging{cursor:grabbing}
      .crop-stage canvas{display:block;width:100%;height:100%}
      .crop-ring{position:absolute;inset:0;pointer-events:none;
        box-shadow:0 0 0 999px rgba(15,23,42,.55);border-radius:50%;margin:14px}
      .crop-zoom{display:flex;align-items:center;gap:10px;margin:16px 0 4px}
      .crop-zoom input{flex:1;accent-color:#00932e}
      .crop-zoom i{color:#667085;font-size:12px}
      .crop-actions{display:flex;gap:10px;justify-content:center;margin-top:14px}
      .crop-actions button{border:0;border-radius:6px;padding:11px 18px;font-weight:800;font-size:13px;
        cursor:pointer;font-family:inherit}
      .crop-save{background:#00932e;color:#fff}
      .crop-cancel{background:#eef2f7;color:#344054}
      .crop-hint{font-size:12px;color:#98a2b3;margin-top:10px}
    `;
    document.head.appendChild(style);
  }

  function close() {
    if (!overlay) return;
    overlay.remove();
    overlay = null;
  }

  function open(file) {
    css();
    return new Promise((resolve) => {
      const reader = new FileReader();
      reader.onerror = () => resolve(null);
      reader.onload = () => {
        const img = new Image();
        img.onerror = () => resolve(null);
        img.onload = () => build(img, resolve);
        img.src = String(reader.result || "");
      };
      reader.readAsDataURL(file);
    });
  }

  function build(img, resolve) {
    close();
    overlay = document.createElement("div");
    overlay.className = "crop-back";
    overlay.innerHTML = `
      <div class="crop-box" role="dialog" aria-modal="true" aria-label="Crop your photo">
        <h2>Position your photo</h2>
        <p>Drag to move, and use the slider to zoom. The circle is what appears on your card.</p>
        <div class="crop-stage" id="cropStage">
          <canvas id="cropCanvas" width="${VIEW}" height="${VIEW}"></canvas>
          <div class="crop-ring"></div>
        </div>
        <div class="crop-zoom">
          <i class="fas fa-image" aria-hidden="true"></i>
          <input id="cropZoom" type="range" min="100" max="300" value="100" aria-label="Zoom">
          <i class="fas fa-magnifying-glass-plus" aria-hidden="true"></i>
        </div>
        <div class="crop-actions">
          <button type="button" class="crop-cancel" id="cropCancel">Cancel</button>
          <button type="button" class="crop-save" id="cropSave">Use this photo</button>
        </div>
        <div class="crop-hint">Saved as a ${OUTPUT}&times;${OUTPUT} square</div>
      </div>`;
    document.body.appendChild(overlay);

    const canvas = overlay.querySelector("#cropCanvas");
    const ctx = canvas.getContext("2d");
    const stage = overlay.querySelector("#cropStage");
    const zoom = overlay.querySelector("#cropZoom");

    // "cover" scale: the smallest zoom that still fills the frame, so there is never a gap.
    const base = Math.max(VIEW / img.width, VIEW / img.height);
    let scale = base;
    let x = (VIEW - img.width * base) / 2;
    let y = (VIEW - img.height * base) / 2;

    function clamp() {
      const w = img.width * scale;
      const h = img.height * scale;
      x = Math.min(0, Math.max(VIEW - w, x));
      y = Math.min(0, Math.max(VIEW - h, y));
    }

    function draw() {
      clamp();
      ctx.fillStyle = "#0f172a";
      ctx.fillRect(0, 0, VIEW, VIEW);
      ctx.drawImage(img, x, y, img.width * scale, img.height * scale);
    }

    zoom.addEventListener("input", () => {
      const next = base * (Number(zoom.value) / 100);
      // Zoom about the centre, so the face stays put instead of drifting to a corner.
      const cx = (VIEW / 2 - x) / scale;
      const cy = (VIEW / 2 - y) / scale;
      scale = next;
      x = VIEW / 2 - cx * scale;
      y = VIEW / 2 - cy * scale;
      draw();
    });

    let dragging = false;
    let lastX = 0;
    let lastY = 0;
    const start = (e) => {
      dragging = true;
      stage.classList.add("dragging");
      const p = e.touches ? e.touches[0] : e;
      lastX = p.clientX;
      lastY = p.clientY;
    };
    const move = (e) => {
      if (!dragging) return;
      e.preventDefault();
      const p = e.touches ? e.touches[0] : e;
      x += p.clientX - lastX;
      y += p.clientY - lastY;
      lastX = p.clientX;
      lastY = p.clientY;
      draw();
    };
    const end = () => {
      dragging = false;
      stage.classList.remove("dragging");
    };

    stage.addEventListener("mousedown", start);
    stage.addEventListener("touchstart", start, { passive: true });
    window.addEventListener("mousemove", move);
    window.addEventListener("touchmove", move, { passive: false });
    window.addEventListener("mouseup", end);
    window.addEventListener("touchend", end);

    function cleanup() {
      window.removeEventListener("mousemove", move);
      window.removeEventListener("touchmove", move);
      window.removeEventListener("mouseup", end);
      window.removeEventListener("touchend", end);
      close();
    }

    overlay.querySelector("#cropCancel").addEventListener("click", () => {
      cleanup();
      resolve(null);
    });

    overlay.querySelector("#cropSave").addEventListener("click", () => {
      // Re-render at export size rather than scaling the preview up, so the saved photo
      // keeps the detail the original had.
      const out = document.createElement("canvas");
      out.width = OUTPUT;
      out.height = OUTPUT;
      const k = OUTPUT / VIEW;
      const octx = out.getContext("2d");
      octx.fillStyle = "#ffffff";
      octx.fillRect(0, 0, OUTPUT, OUTPUT);
      octx.drawImage(img, x * k, y * k, img.width * scale * k, img.height * scale * k);
      out.toBlob((blob) => {
        cleanup();
        resolve(blob);
      }, "image/jpeg", QUALITY);
    });

    document.addEventListener("keydown", function esc(e) {
      if (e.key !== "Escape") return;
      document.removeEventListener("keydown", esc);
      cleanup();
      resolve(null);
    });

    draw();
  }

  return { open };
})();
