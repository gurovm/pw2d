<div 
    x-data="{
        prompt: {{ Illuminate\Support\Js::from($aiPromptString) }},
        apiKey: {{ Illuminate\Support\Js::from($apiKey) }},
        isLoading: false,
        reportGenerated: false,
        parsedReport: '',
        error: '',
        
        async generate() {
            this.isLoading = true;
            this.error = '';
            try {
                const response = await fetch('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro:generateContent?key=' + this.apiKey, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        contents: [{ parts: [{ text: this.prompt }] }],
                        generationConfig: { temperature: 0.4, maxOutputTokens: 8192 }
                    })
                });
                
                if(!response.ok) {
                    const errText = await response.text();
                    throw new Error('API Error: ' + response.status);
                }
                
                const data = await response.json();
                const content = data.candidates?.[0]?.content?.parts?.[0]?.text;
                
                if(!content) {
                    throw new Error('Empty response from AI or blocked by safety settings.');
                }
                
                let htmlFormat = content
                    .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
                    .replace(/\*(.*?)\*/g, '<em>$1</em>')
                    .replace(/\n\n/g, '<br><br>')
                    .replace(/\n\- /g, '<br>• ')
                    .replace(/\n\d+\. /g, '<br><br><strong>$&</strong>');
                    
                this.parsedReport = htmlFormat;
                this.reportGenerated = true;
            } catch(e) {
                this.error = e.message;
            } finally {
                this.isLoading = false;
            }
        },
        
        copyReport() {
            const text = document.createElement('textarea');
            text.innerHTML = this.parsedReport;
            navigator.clipboard.writeText(text.value);
            new FilamentNotification().title('Copied to clipboard').success().send();
        }
    }"
    class="space-y-4"
>
    <div x-show="!reportGenerated">
        <label class="text-sm font-medium leading-6 text-gray-950 dark:text-white">Review Prompt Data</label>
        <p class="text-sm text-gray-500 mb-2">This data will be sent directly from your browser to bypass server timeouts.</p>
        <textarea x-model="prompt" rows="15" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 font-mono text-sm dark:bg-gray-900 dark:border-white/10 dark:text-white"></textarea>
        
        <div class="mt-4 flex justify-end">
            <button type="button" x-on:click="generate" :disabled="isLoading" class="fi-btn flex items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold text-white shadow-sm hover:opacity-90 disabled:opacity-50 transition-all bg-amber-600 dark:bg-amber-600" style="background-color: #d97706;">
                <span x-show="!isLoading">Run AI Analysis</span>
                <span x-show="isLoading" class="flex items-center" style="display: none;">
                    <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                    Analyzing Trends... (This may take up to 60s)
                </span>
            </button>
        </div>
        <div x-show="error" x-text="error" class="text-danger-600 mt-2 text-sm font-semibold" style="display: none;"></div>
    </div>
    
    <div x-show="reportGenerated" style="display: none;">
        <div class="flex justify-between items-center mb-4 pb-4 border-b dark:border-white/10">
            <h3 class="text-xl font-bold dark:text-white">AI Analysis Complete</h3>
            <button type="button" x-on:click="copyReport" class="fi-btn flex items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold text-white shadow-sm hover:opacity-90 transition-all bg-amber-600 dark:bg-amber-600" style="background-color: #d97706;">
                <svg class="w-5 h-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                Copy Report
            </button>
        </div>
        <div x-html="parsedReport" class="prose max-w-none overflow-y-auto w-full dark:prose-invert" style="max-height: 65vh; padding-right: 1rem; white-space: pre-wrap;"></div>
    </div>
</div>
