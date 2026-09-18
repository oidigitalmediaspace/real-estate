    columnLabels = labelsFromStatuses(PIPELINES[currentPipeline].statuses);
    
    async function initApp() {
      return withProcessing('Verificando o acesso ao CRM...', async () => {
        try {
          const res = await apiFetch(`${API_URL}?action=session`);
          const session = await res.json();
          if (!session.success) throw new Error(session.error || 'Falha ao verificar a sessão.');
          if (session.needs_setup) return renderSetupScreen();
          if (!session.authenticated) return renderLoginScreen();
          currentUserRole = session.role || 'user';
          await bootPipelines();
          await loadLeads({ withinOperation: true });
        } catch (error) {
          app.innerHTML = `<main class="app-shell flex min-h-screen items-center justify-center px-4">
            <section class="soft-panel max-w-lg rounded-2xl p-6" role="alert">
              <h1 class="text-xl font-bold">Não foi possível conectar ao CRM</h1>
              <p class="mt-3 text-sm text-rose-200">${escapeHtml(error.message)}</p>
              <button id="retry-startup" class="mt-5 rounded-xl bg-[#E8205E] px-4 py-2 text-white">Tentar novamente</button>
            </section></main>`;
          document.getElementById('retry-startup').addEventListener('click', initApp);
        }
      });
    }

    initApp();
