    async function apiFetch(url, opts = {}) {
      const res = await fetch(url, { credentials: 'same-origin', ...opts });
      if (res.status === 401) {
        sessionExpired = true;
        throw new Error('Sua sessão expirou. Entre novamente.');
      }
      if (!res.ok) {
        const errorData = await res.json().catch(() => ({}));
        throw new Error(errorData.error || `O servidor respondeu com erro ${res.status}. Tente novamente após conferir a conexão.`);
      }
      return res;
    }

    const isProcessing = () => pendingOperations.size > 0;

    function renderProcessingOverlay() {
      const root = document.getElementById('processing-root');
      const messages = [...pendingOperations.values()];
      if (!messages.length) {
        root.replaceChildren();
        return;
      }
      root.innerHTML = `
        <div class="fixed inset-0 z-[100] flex cursor-wait items-center justify-center bg-black/60 px-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="processing-title" aria-describedby="processing-message processing-hint" tabindex="-1" id="processing-dialog">
          <div class="flex w-full max-w-md items-center gap-4 rounded-2xl border border-rose-500/25 bg-[#101216] p-6 shadow-[0_24px_90px_rgba(0,0,0,.65),0_0_45px_rgba(232,32,94,.12)]">
            <span class="h-8 w-8 shrink-0 animate-spin rounded-full border-2 border-white/10 border-r-orange-400 border-t-[#E8205E]" aria-hidden="true"></span>
            <div role="status" aria-live="polite" aria-atomic="true">
              <p id="processing-title" class="text-xs font-extrabold uppercase tracking-[0.2em] text-orange-300">Processando</p>
              <p id="processing-message" class="mt-2 text-sm font-semibold leading-6 text-zinc-100">${escapeHtml(messages[messages.length - 1])}</p>
              <p id="processing-hint" class="mt-2 text-xs leading-5 text-zinc-400">Aguarde a conclusão. As ações estão temporariamente bloqueadas.</p>
            </div>
          </div>
        </div>`;
      document.getElementById('processing-dialog').focus({ preventScroll: true });
    }

    async function withProcessing(message, task, { allowNested = false } = {}) {
      if (isProcessing() && !allowNested) return;
      const token = Symbol('operation');
      const first = !isProcessing();
      if (first) {
        processingFocus = document.activeElement;
        processingOverflow = document.body.style.overflow;
      }
      pendingOperations.set(token, message);
      try {
        app.inert = true;
        app.setAttribute('aria-busy', 'true');
        document.body.style.overflow = 'hidden';
        renderProcessingOverlay();
        if (first) {
          processingTimer = setTimeout(() => {
            const hint = document.getElementById('processing-hint');
            if (hint) hint.textContent = 'A resposta está demorando um pouco. Ainda estamos aguardando a conclusão; não repita a ação.';
          }, 10000);
        }
        return await task();
      } finally {
        pendingOperations.delete(token);
        if (isProcessing()) {
          renderProcessingOverlay();
        } else {
          clearTimeout(processingTimer);
          processingTimer = null;
          renderProcessingOverlay();
          app.inert = false;
          app.removeAttribute('aria-busy');
          document.body.style.overflow = processingOverflow;
          if (sessionExpired) {
            sessionExpired = false;
            renderLoginScreen('Sua sessão expirou. Entre novamente.');
          } else if (processingFocus?.isConnected) {
            processingFocus.focus({ preventScroll: true });
          }
          processingFocus = null;
        }
      }
    }

    // Também bloqueia eventos já enfileirados e navegação pelo teclado.
    ['click', 'dblclick', 'pointerdown', 'keydown', 'submit', 'dragstart', 'drop'].forEach((type) => {
      document.addEventListener(type, (event) => {
        if (!isProcessing()) return;
        event.preventDefault();
        event.stopImmediatePropagation();
      }, true);
    });
