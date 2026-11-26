// Aplica o tema salvo antes do primeiro paint (evita "flash" claro/escuro).
// Arquivo externo (não inline) para a CSP poder exigir script-src 'self'.
try { document.documentElement.dataset.theme = localStorage.getItem('bianchi.theme') || 'dark' } catch (e) { /* storage indisponível */ }
