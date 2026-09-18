const API_URL = 'api.php';
    const ADMIN_EMAIL = 'comercial@oidigitalmedia.com';
    let PIPELINES = {
      real_estate: {
        key: 'real_estate',
        driver: 'file',
        title: 'Real Estate',
        heading: 'Real Estate CRM',
        eyebrow: 'Pipeline',
        subtitle: 'Pipeline principal com importação manual e status salvos em produção.',
        badge: 'CSV manual',
        statuses: ['Novo', 'Em andamento', 'Fechado'],
        supportsImport: true,
        supportsSync: false,
        supportsDelete: false,
        searchPlaceholder: 'Buscar por nome'
      }
    };
    const TIER_OPTIONS = ['All', 'A+', 'A', 'B', 'C', 'D'];
    const PAGE_SIZE = 15;
    const app = document.getElementById('app');

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !isProcessing()) {
        const modalRoot = document.getElementById('modal-root');
        const importRoot = document.getElementById('import-root');
        if (modalRoot && modalRoot.innerHTML.trim() !== '') {
          modalRoot.innerHTML = '';
          selectedLeadId = null;
        } else if (importRoot && importRoot.innerHTML.trim() !== '') {
          importRoot.innerHTML = '';
        }
      }
    });

    let currentPipeline = 'real_estate';
    let leads = [];
    let filters = { search: '', tier: 'All', approach: 'All', sort: 'tier_desc' };
    const SORT_OPTIONS = [
      { value: 'tier_desc', label: 'Maior Tier' },
      { value: 'tier_asc', label: 'Menor Tier' },
      { value: 'name_asc', label: 'Ordem alfabética (A–Z)' }
    ];
    const nameCollator = new Intl.Collator('pt-BR', { sensitivity: 'base', numeric: true });
    let columnLimits = {};
    let columnLabels = {};
    let currentStatuses = [...PIPELINES.real_estate.statuses];
    let selectedLeadId = null;
    let lastSynced = '';
    let lastUpdated = '';
    let syncMessage = '';
    let syncError = '';
    let isSyncing = false;
    let isColumnOperationPending = false;
    let draggedLeadId = null;
    let columnPointerDrag = null;
    let dragJustEnded = false;
    let pipelineDropdownAbortController = null;
    const updatingLeadIds = new Set();
    const pendingOperations = new Map();
    let processingFocus = null;
    let processingTimer = null;
    let processingOverflow = '';
    let sessionExpired = false;
    let currentUserRole = null;

    const escapeHtml = (value) => String(value || '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');

    const normalize = (value) => String(value || '').toLowerCase().trim();
    const pipelineConfig = () => PIPELINES[currentPipeline];
    const boardStatuses = () => currentStatuses;
    const apiEndpoint = (action) => `${API_URL}?action=${encodeURIComponent(action)}&pipeline=${encodeURIComponent(currentPipeline)}`;
    const getLead = (id) => leads.find((lead) => lead.Lead_ID === id);
    const uniqueApproaches = () => [...new Set(leads.map((lead) => lead.Approach_Type).filter(Boolean))].sort();
