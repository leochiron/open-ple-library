<?php
/**
 * @var App\Services\I18nService $i18n
 * @var array $config
 */
?>
<div class="container" style="max-width: 800px; margin: 4rem auto; padding: 0 1rem;">
    <div class="card" style="padding: 2rem; border-radius: var(--radius); box-shadow: var(--shadow); background: white;">
        <h1 style="margin: 0 0 1rem 0; font-family: var(--font-heading); color: var(--structure);">
            🔄 Synchronisation Google Drive
        </h1>
        
        <div class="alert alert-info" style="padding: 1rem; background: #e3f2fd; border-left: 4px solid #2196F3; margin-bottom: 2rem; border-radius: 4px;">
            <p style="margin: 0 0 0.5rem 0; font-weight: 600;">À propos de la synchronisation</p>
            <p style="margin: 0; font-size: 0.95rem; line-height: 1.5;">
                Cette action télécharge l'intégralité du dossier Google Drive configuré et remplace le contenu local.
                <strong>Les fichiers locaux existants seront supprimés.</strong>
            </p>
        </div>

        <div id="sync-form-container">
            <form id="sync-form" style="display: flex; flex-direction: column; gap: 1.5rem;">
                <div class="form-group">
                    <label for="sync-password" style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: var(--structure);">
                        Mot de passe de synchronisation
                    </label>
                    <input 
                        type="password" 
                        id="sync-password" 
                        name="sync_password" 
                        required 
                        autofocus
                        style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; font-size: 1rem;"
                        placeholder="Entrez le mot de passe de sync"
                    >
                </div>

                <div class="form-group" style="display: flex; align-items: center; gap: 0.5rem;">
                    <input 
                        type="checkbox" 
                        id="confirm-sync" 
                        required
                        style="width: 18px; height: 18px;"
                    >
                    <label for="confirm-sync" style="margin: 0; font-size: 0.95rem;">
                        Je comprends que cette action remplacera tous les fichiers locaux
                    </label>
                </div>

                <button 
                    type="submit" 
                    id="sync-button"
                    style="padding: 0.875rem 2rem; background: linear-gradient(90deg, var(--secondary), var(--structure)); color: white; border: none; border-radius: 6px; font-size: 1rem; font-weight: 600; cursor: pointer; font-family: var(--font-ui); transition: transform 0.12s ease, box-shadow 0.12s ease; box-shadow: var(--shadow);"
                >
                    🚀 Lancer la synchronisation
                </button>
            </form>
        </div>

        <div id="sync-progress" style="display: none; margin-top: 2rem;">
            <div class="progress-bar" style="background: #f0f0f0; border-radius: 8px; overflow: hidden; height: 24px; margin-bottom: 1rem;">
                <div id="progress-fill" style="background: linear-gradient(90deg, var(--tertiary), var(--primary)); height: 100%; width: 0%; transition: width 0.3s ease; display: flex; align-items: center; justify-content: center; color: white; font-size: 0.875rem; font-weight: 600;">
                    <span id="progress-text">0%</span>
                </div>
            </div>
            
            <div id="sync-log" style="background: #1e1e1e; color: #d4d4d4; padding: 1rem; border-radius: 6px; font-family: 'Courier New', monospace; font-size: 0.875rem; max-height: 400px; overflow-y: auto; line-height: 1.6;">
                <div id="log-content"></div>
            </div>
        </div>

        <div id="sync-result" style="display: none; margin-top: 2rem;"></div>

        <div style="margin-top: 2rem; padding-top: 2rem; border-top: 1px solid #eee;">
            <a href="/" style="color: var(--secondary); text-decoration: none; font-weight: 500;">
                ← Retour à la bibliothèque
            </a>
        </div>
    </div>
</div>

<script>
document.getElementById('sync-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const password = document.getElementById('sync-password').value;
    const formContainer = document.getElementById('sync-form-container');
    const progressContainer = document.getElementById('sync-progress');
    const resultContainer = document.getElementById('sync-result');
    const logContent = document.getElementById('log-content');
    const progressFill = document.getElementById('progress-fill');
    const progressText = document.getElementById('progress-text');
    
    // Hide form, show progress
    formContainer.style.display = 'none';
    progressContainer.style.display = 'block';
    resultContainer.style.display = 'none';
    
    // Add initial log
    addLog('🔄 Connexion à Google Drive...', 'info');
    setProgress(10);
    
    try {
        const formData = new FormData();
        formData.append('sync_password', password);
        formData.append('stream', 'true'); // Enable Server-Sent Events
        
        // Use EventSource for Server-Sent Events
        const eventSource = new EventSource(window.location.pathname + '?' + new URLSearchParams({
            sync_password: password,
            stream: 'true'
        }).toString());
        
        // Fallback to POST if EventSource fails (some servers don't support GET with body)
        // So we'll use fetch with SSE handling
        const response = await fetch(window.location.pathname, {
            method: 'POST',
            body: formData
        });
        
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        let progressPercent = 20;
        
        while (true) {
            const {done, value} = await reader.read();
            if (done) break;
            
            buffer += decoder.decode(value, {stream: true});
            const lines = buffer.split('\n\n');
            buffer = lines.pop(); // Keep incomplete message in buffer
            
            for (const line of lines) {
                if (!line.trim()) continue;
                
                const eventMatch = line.match(/^event: (\w+)\ndata: (.+)$/s);
                if (eventMatch) {
                    const [, eventType, dataStr] = eventMatch;
                    const data = JSON.parse(dataStr);
                    
                    if (eventType === 'progress') {
                        addLog(data.message, 'info');
                        
                        // Increment progress gradually
                        if (progressPercent < 95) {
                            progressPercent = Math.min(95, progressPercent + 2);
                            setProgress(progressPercent);
                        }
                    } else if (eventType === 'complete') {
                        setProgress(100);
                        addLog(data.message, 'success');
                        addLog(`📁 ${data.stats.folders_created} dossiers créés`, 'success');
                        addLog(`📄 ${data.stats.files_downloaded} fichiers téléchargés`, 'success');
                        addLog(`🗑️ ${data.stats.files_deleted} fichiers supprimés`, 'success');
                        addLog(`⚡ ${formatBytes(data.stats.bytes_transferred)} transférés`, 'success');
                        addLog(`⏱️ Durée: ${data.stats.duration}s`, 'success');
                        
                        // Show success message
                        setTimeout(() => {
                            resultContainer.innerHTML = `
                                <div style="padding: 1.5rem; background: #e8f5e9; border-left: 4px solid #4caf50; border-radius: 4px;">
                                    <h3 style="margin: 0 0 0.5rem 0; color: #2e7d32;">Synchronisation réussie !</h3>
                                    <p style="margin: 0; color: #1b5e20;">Le contenu a été synchronisé depuis Google Drive.</p>
                                    <a href="/" style="display: inline-block; margin-top: 1rem; padding: 0.5rem 1rem; background: var(--tertiary); color: white; border-radius: 4px; text-decoration: none; font-weight: 600;">
                                        Voir la bibliothèque
                                    </a>
                                </div>
                            `;
                            resultContainer.style.display = 'block';
                        }, 1000);
                    } else if (eventType === 'error') {
                        addLog('❌ Erreur: ' + data.message, 'error');
                        
                        resultContainer.innerHTML = `
                            <div style="padding: 1.5rem; background: #ffebee; border-left: 4px solid #f44336; border-radius: 4px;">
                                <h3 style="margin: 0 0 0.5rem 0; color: #c62828;">Échec de la synchronisation</h3>
                                <p style="margin: 0; color: #b71c1c;">${data.message}</p>
                                <button onclick="location.reload()" style="margin-top: 1rem; padding: 0.5rem 1rem; background: var(--secondary); color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: 600;">
                                    Réessayer
                                </button>
                            </div>
                        `;
                        resultContainer.style.display = 'block';
                    }
                }
            }
        }
        
    } catch (error) {
        setProgress(0);
        addLog('❌ Erreur réseau: ' + error.message, 'error');
        
        resultContainer.innerHTML = `
            <div style="padding: 1.5rem; background: #ffebee; border-left: 4px solid #f44336; border-radius: 4px;">
                <h3 style="margin: 0 0 0.5rem 0; color: #c62828;">Erreur de connexion</h3>
                <p style="margin: 0; color: #b71c1c;">${error.message}</p>
                <button onclick="location.reload()" style="margin-top: 1rem; padding: 0.5rem 1rem; background: var(--secondary); color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: 600;">
                    Réessayer
                </button>
            </div>
        `;
        resultContainer.style.display = 'block';
    }
});

function addLog(message, type = 'info') {
    const logContent = document.getElementById('log-content');
    const colors = {
        info: '#58a6ff',
        success: '#3fb950',
        error: '#f85149'
    };
    const color = colors[type] || '#d4d4d4';
    const timestamp = new Date().toLocaleTimeString('fr-FR');
    
    const logLine = document.createElement('div');
    logLine.style.color = color;
    logLine.textContent = `[${timestamp}] ${message}`;
    logContent.appendChild(logLine);
    
    // Auto-scroll to bottom
    document.getElementById('sync-log').scrollTop = document.getElementById('sync-log').scrollHeight;
}

function setProgress(percent) {
    const progressFill = document.getElementById('progress-fill');
    const progressText = document.getElementById('progress-text');
    progressFill.style.width = percent + '%';
    progressText.textContent = percent + '%';
}

function formatBytes(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}
</script>
