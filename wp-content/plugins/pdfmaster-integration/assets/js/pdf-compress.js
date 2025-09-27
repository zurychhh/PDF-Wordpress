/**
 * PDFMaster - Frontend JavaScript dla kompresji PDF
 * 
 * Ten plik obsługuje całą interakcję użytkownika z narzędziem kompresji PDF.
 * Zaprojektowany z myślą o WordPress environment i Elementor Pro integration.
 * 
 * Kluczowe funkcjonalności:
 * 1. Drag & Drop file upload z visual feedback
 * 2. AJAX communication z WordPress backend 
 * 3. Real-time progress tracking i error handling
 * 4. Responsive UI states (loading, success, error)
 * 5. Integration z WordPress nonce system dla bezpieczeństwa
 */

(function($) {
    'use strict';
    
    // Główny obiekt PDFMaster - enkapsuluje całą funkcjonalność
    const PDFMaster = {

        // Konfiguracja pobrana z WordPress (wp_localize_script)
        config: (typeof pdfmaster_ajax !== 'undefined' ? pdfmaster_ajax : {}),

        // Flag to prevent multiple initializations
        initialized: false,
        
        // Current state aplikacji
        state: {
            isProcessing: false,
            selectedFile: null,
            compressionLevel: 2, // Domyślnie średnia kompresja
            currentXHR: null     // Referencja do aktualnego AJAX request
        },
        
        // Elementy DOM - cache dla wydajności
        elements: {
            $uploadArea: null,
            $fileInput: null,
            $compressionSelect: null,
            $processButton: null,
            $progressBar: null,
            $progressFill: null,
            $creditsInfo: null,
            $errorContainer: null
        },
        
        /**
         * Inicjalizacja aplikacji
         * Wywoływana gdy DOM jest gotowy
         */
        init() {
            // Prevent multiple initializations
            if (this.initialized) {
                console.warn('PDFMaster: Already initialized, skipping');
                return;
            }

            console.log('PDFMaster: Inicjalizacja frontendu');
            console.log('PDFMaster: Config:', this.config);

            // Sprawdzenie czy config został załadowany
            if (!this.config.ajax_url || !this.config.nonce) {
                console.error('PDFMaster: Brak konfiguracji z WordPress', {
                    ajax_url: this.config.ajax_url,
                    nonce: this.config.nonce,
                    config: this.config
                });
                return;
            }
            
            // Cache elementów DOM
            this.cacheElements();
            
            // Sprawdzenie czy wszystkie wymagane elementy istnieją
            if (!this.validateElements()) {
                console.error('PDFMaster: Nie znaleziono wymaganych elementów DOM');
                return;
            }
            
            // Rejestracja event listeners
            this.bindEvents();
            
            // Inicjalizacja drag & drop
            this.initDragAndDrop();
            
            // Wczytanie aktualnego stanu kredytów
            this.loadCreditsInfo();

            // Mark as initialized
            this.initialized = true;

            console.log('PDFMaster: Frontend gotowy do użycia');
        },
        
        /**
         * Cache elementów DOM dla lepszej wydajności
         */
        cacheElements() {
            this.elements.$uploadArea = $('.pdf-upload-area');
            this.elements.$fileInput = $('#compress-file');
            this.elements.$compressionSelect = $('.compression-level-select');
            this.elements.$processButton = $('.process-btn');
            this.elements.$progressBar = $('.progress-bar');
            this.elements.$progressFill = $('.progress-fill');
            this.elements.$creditsInfo = $('.credits-count');
            this.elements.$errorContainer = $('.error-message');

            // Debug log elements found
            console.log('PDFMaster: DOM Elements cached:', {
                uploadArea: this.elements.$uploadArea.length,
                fileInput: this.elements.$fileInput.length,
                processButton: this.elements.$processButton.length
            });

            // Additional debug - check if upload area is visible and positioned correctly
            if (this.elements.$uploadArea.length > 0) {
                const uploadAreaElement = this.elements.$uploadArea[0];
                const rect = uploadAreaElement.getBoundingClientRect();
                const styles = window.getComputedStyle(uploadAreaElement);

                console.log('PDFMaster: Upload Area Debug:', {
                    visible: styles.display !== 'none' && styles.visibility !== 'hidden',
                    position: { top: rect.top, left: rect.left, width: rect.width, height: rect.height },
                    zIndex: styles.zIndex,
                    pointerEvents: styles.pointerEvents,
                    cursor: styles.cursor
                });
            }
        },
        
        /**
         * Walidacja czy wszystkie wymagane elementy DOM istnieją
         */
        validateElements() {
            const required = ['$uploadArea', '$fileInput', '$processButton'];
            return required.every(element => this.elements[element] && this.elements[element].length > 0);
        },
        
        /**
         * Rejestracja wszystkich event listeners
         */
        bindEvents() {
            console.log('PDFMaster: Binding events...');

            // Unbind existing events to prevent duplicates
            this.elements.$uploadArea.off('.pdfmaster');
            this.elements.$fileInput.off('.pdfmaster');
            this.elements.$compressionSelect.off('.pdfmaster');
            this.elements.$processButton.off('.pdfmaster');
            $(document).off('keydown.pdfmaster');

            // Kliknięcie w obszar upload
            this.elements.$uploadArea.on('click.pdfmaster', (e) => {
                console.log('PDFMaster: Upload area clicked!', e);

                // Ignore clicks on download button and other interactive elements
                if ($(e.target).hasClass('download-btn') ||
                    $(e.target).closest('.download-btn').length > 0 ||
                    $(e.target).hasClass('compression-success') ||
                    $(e.target).closest('.compression-success').length > 0) {
                    console.log('PDFMaster: Click on download button or success area, ignoring');
                    return; // Let the download button handle its own click
                }

                e.preventDefault();
                e.stopPropagation();

                // Use native click() method instead of jQuery trigger to avoid stack overflow
                if (this.elements.$fileInput[0]) {
                    console.log('PDFMaster: Triggering file input click');

                    // Try multiple methods to ensure compatibility
                    try {
                        // Method 1: Direct native click
                        this.elements.$fileInput[0].click();
                    } catch (error1) {
                        console.warn('PDFMaster: Native click failed, trying alternative:', error1);
                        try {
                            // Method 2: Dispatch click event
                            const clickEvent = new MouseEvent('click', {
                                view: window,
                                bubbles: true,
                                cancelable: true
                            });
                            this.elements.$fileInput[0].dispatchEvent(clickEvent);
                        } catch (error2) {
                            console.error('PDFMaster: All click methods failed:', error2);
                        }
                    }
                } else {
                    console.error('PDFMaster: File input element not found!');
                }
            });
            
            // Zmiana pliku przez input
            this.elements.$fileInput.on('change.pdfmaster', (e) => {
                console.log('PDFMaster: File input changed!', e.target.files);
                this.handleFileSelect(e.target.files[0]);
            });

            // Additional debug event to track file input clicks
            this.elements.$fileInput.on('click.pdfmaster', (e) => {
                console.log('PDFMaster: File input clicked directly!');
            });

            // Zmiana poziomu kompresji
            this.elements.$compressionSelect.on('change.pdfmaster', (e) => {
                this.state.compressionLevel = parseInt(e.target.value);
                this.updateCompressionPreview();
            });

            // Kliknięcie przycisku "Kompresuj"
            this.elements.$processButton.on('click.pdfmaster', (e) => {
                e.preventDefault();
                this.startCompression();
            });

            // Anulowanie operacji (jeśli użytkownik chce przerwać)
            $(document).on('keydown.pdfmaster', (e) => {
                if (e.key === 'Escape' && this.state.isProcessing) {
                    this.cancelOperation();
                }
            });

            // Download button handler (delegated event since button is added dynamically)
            this.elements.$uploadArea.on('click.pdfmaster', '.download-btn', (e) => {
                console.log('PDFMaster: Download button clicked!', e.target.href);
                e.stopPropagation(); // Prevent upload area click
                // Let the browser handle the download naturally via href
            });
        },
        
        /**
         * Inicjalizacja drag & drop functionality
         */
        initDragAndDrop() {
            const $uploadArea = this.elements.$uploadArea;

            // Remove existing drag events to prevent stacking
            $uploadArea.off('.pdfmaster-drag');

            // Zapobieganie domyślnemu zachowaniu przeglądarki
            $uploadArea.on('dragover.pdfmaster-drag dragenter.pdfmaster-drag', (e) => {
                e.preventDefault();
                e.stopPropagation();
                $uploadArea.addClass('dragover');
            });

            $uploadArea.on('dragleave.pdfmaster-drag dragend.pdfmaster-drag', (e) => {
                e.preventDefault();
                e.stopPropagation();
                // Only remove dragover if we're actually leaving the upload area
                if (!$uploadArea[0].contains(e.relatedTarget)) {
                    $uploadArea.removeClass('dragover');
                }
            });

            // Obsługa drop
            $uploadArea.on('drop.pdfmaster-drag', (e) => {
                e.preventDefault();
                e.stopPropagation();
                $uploadArea.removeClass('dragover');

                const files = e.originalEvent.dataTransfer.files;
                if (files.length > 0) {
                    this.handleFileSelect(files[0]);
                }
            });
        },
        
        /**
         * Obsługa wyboru pliku (przez click lub drag&drop)
         */
        handleFileSelect(file) {
            console.log('PDFMaster: Wybrano plik:', file.name);
            
            // Reset poprzednich błędów
            this.clearError();
            
            // Walidacja pliku
            const validation = this.validateFile(file);
            if (!validation.valid) {
                this.showError(validation.message);
                return;
            }
            
            // Zapisanie pliku w state
            this.state.selectedFile = file;
            
            // Update UI
            this.updateFileDisplay(file);
            this.enableProcessButton();
        },
        
        /**
         * Walidacja wybranego pliku
         */
        validateFile(file) {
            // Sprawdzenie czy plik istnieje
            if (!file) {
                return { valid: false, message: this.config.messages.error_type };
            }
            
            // Sprawdzenie typu pliku
            if (file.type !== 'application/pdf') {
                return { valid: false, message: this.config.messages.error_type };
            }
            
            // Sprawdzenie rozmiaru (50MB = 50 * 1024 * 1024 bytes)
            const maxSize = 50 * 1024 * 1024;
            if (file.size > maxSize) {
                return { valid: false, message: this.config.messages.error_size };
            }
            
            // Sprawdzenie czy plik nie jest pusty
            if (file.size === 0) {
                return { valid: false, message: 'Plik jest pusty lub uszkodzony.' };
            }
            
            return { valid: true };
        },
        
        /**
         * Aktualizacja wyświetlania wybranego pliku
         */
        updateFileDisplay(file) {
            const $uploadText = this.elements.$uploadArea.find('.upload-text');
            const fileSize = this.formatFileSize(file.size);
            
            $uploadText.html(`
                <strong>${file.name}</strong><br>
                <span class="file-size">${fileSize}</span>
            `);
            
            this.elements.$uploadArea.addClass('file-selected');
        },
        
        /**
         * Formatowanie rozmiaru pliku do czytelnej formy
         */
        formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        },
        
        /**
         * Włączenie przycisku przetwarzania
         */
        enableProcessButton() {
            this.elements.$processButton
                .prop('disabled', false)
                .removeClass('disabled')
                .text(this.config.messages.process || 'Kompresuj PDF');
        },
        
        /**
         * Rozpoczęcie procesu kompresji
         */
        startCompression() {
            // Sprawdzenie czy plik jest wybrany
            if (!this.state.selectedFile) {
                this.showError('Najpierw wybierz plik PDF do kompresji.');
                return;
            }
            
            // Sprawdzenie czy nie trwa już inna operacja
            if (this.state.isProcessing) {
                console.log('PDFMaster: Operacja już w toku');
                return;
            }
            
            console.log('PDFMaster: Rozpoczynanie kompresji');
            
            // Update state
            this.state.isProcessing = true;
            
            // Update UI do stanu loading
            this.showLoadingState();
            
            // Przygotowanie danych formularza
            const formData = new FormData();
            formData.append('action', 'pdfmaster_compress');
            formData.append('nonce', this.config.nonce);
            formData.append('pdf_file', this.state.selectedFile);
            formData.append('compression_level', this.state.compressionLevel);
            
            // Wykonanie AJAX request
            this.state.currentXHR = $.ajax({
                url: this.config.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                timeout: 300000, // 5 minut timeout
                
                // Progress callback dla upload
                xhr: () => {
                    const xhr = new window.XMLHttpRequest();
                    xhr.upload.addEventListener('progress', (e) => {
                        if (e.lengthComputable) {
                            const percentComplete = (e.loaded / e.total) * 50; // Upload to 50%
                            this.updateProgress(percentComplete, this.config.messages.uploading);
                        }
                    });
                    return xhr;
                }
            })
            .done((response) => {
                this.handleSuccess(response);
            })
            .fail((xhr, status, error) => {
                this.handleError(xhr, status, error);
            })
            .always(() => {
                this.state.isProcessing = false;
                this.state.currentXHR = null;
            });
        },
        
        /**
         * Przełączenie UI w stan loading
         */
        showLoadingState() {
            // Aktualizacja przycisku
            this.elements.$processButton
                .prop('disabled', true)
                .addClass('loading')
                .html('<span class="loading-spinner"></span> Przetwarzanie...');
            
            // Pokazanie progress bar
            this.elements.$progressBar.show();
            this.updateProgress(0, this.config.messages.uploading);
            
            // Ukrycie błędów
            this.clearError();
        },
        
        /**
         * Aktualizacja progress bar
         */
        updateProgress(percent, message) {
            this.elements.$progressFill.css('width', percent + '%');
            
            if (message) {
                // Dodanie lub aktualizacja komunikatu progress
                let $progressMessage = this.elements.$progressBar.find('.progress-message');
                if ($progressMessage.length === 0) {
                    $progressMessage = $('<div class="progress-message"></div>');
                    this.elements.$progressBar.append($progressMessage);
                }
                $progressMessage.text(message);
            }
        },
        
        /**
         * Obsługa sukcesu operacji
         */
        handleSuccess(response) {
            console.log('PDFMaster: Sukces operacji', response);
            console.log('PDFMaster: Response data details:', JSON.stringify(response.data, null, 2));

            if (response.success && response.data) {
                // Simulate progress completion
                this.updateProgress(100, this.config.messages.success);
                
                // Pokazanie wyników kompresji
                this.showCompressionResults(response.data);
                
                // Aktualizacja licznika kredytów
                this.updateCreditsDisplay(response.data.remaining_credits);
                
                // Reset UI po 10 sekundach (więcej czasu na zobaczenie wyników)
                setTimeout(() => {
                    this.resetToInitialState();
                }, 10000);
                
            } else {
                // API zwróciło sukces, ale bez danych
                const errorMessage = response.data?.message || 'Nieoczekiwany błąd podczas przetwarzania.';
                this.showError(errorMessage);
            }
        },
        
        /**
         * Obsługa błędu operacji
         */
        handleError(xhr, status, error) {
            console.error('PDFMaster: Błąd operacji', { xhr, status, error });
            
            let errorMessage = this.config.messages.error_network;
            
            // Parsowanie konkretnego błędu z serwera
            if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                errorMessage = xhr.responseJSON.data.message;
            } else if (status === 'timeout') {
                errorMessage = 'Operacja trwała zbyt długo. Spróbuj z mniejszym plikiem.';
            } else if (status === 'abort') {
                errorMessage = 'Operacja została anulowana.';
            }
            
            this.showError(errorMessage);
            this.resetToInitialState();
        },
        
        /**
         * Pokazanie wyników kompresji
         */
        showCompressionResults(data) {
            const originalSizeMB = (data.original_size / (1024 * 1024)).toFixed(2);
            const compressedSizeMB = (data.compressed_size / (1024 * 1024)).toFixed(2);
            const compressionRatio = data.compression_ratio || 0;
            
            // Utworzenie komunikatu sukcesu
            const successMessage = `
                <div class="compression-success">
                    <div class="success-icon">✅</div>
                    <h4>Plik został skompresowany!</h4>
                    <div class="compression-stats">
                        <div class="stat">
                            <span class="label">Rozmiar oryginalny:</span>
                            <span class="value">${originalSizeMB} MB</span>
                        </div>
                        <div class="stat">
                            <span class="label">Rozmiar po kompresji:</span>
                            <span class="value">${compressedSizeMB} MB</span>
                        </div>
                        <div class="stat highlight">
                            <span class="label">Zaoszczędzono:</span>
                            <span class="value">${compressionRatio}%</span>
                        </div>
                    </div>
                    <a href="${data.download_url}" class="download-btn" download>
                        📥 Pobierz skompresowany plik
                    </a>
                </div>
            `;
            
            // Pokazanie komunikatu sukcesu w miejsce upload area
            this.elements.$uploadArea.html(successMessage);
            this.elements.$uploadArea.addClass('success-state');
        },
        
        /**
         * Pokazanie błędu
         */
        showError(message) {
            console.error('PDFMaster: Error -', message);
            
            // Znajdź lub utwórz kontener błędu
            let $errorContainer = this.elements.$uploadArea.find('.error-message');
            if ($errorContainer.length === 0) {
                $errorContainer = $('<div class="error-message"></div>');
                this.elements.$uploadArea.append($errorContainer);
            }
            
            $errorContainer.html(`
                <div class="error-content">
                    <span class="error-icon">❌</span>
                    <span class="error-text">${message}</span>
                </div>
            `).show();
            
            // Auto-hide po 5 sekundach
            setTimeout(() => {
                this.clearError();
            }, 5000);
        },
        
        /**
         * Usunięcie komunikatu błędu
         */
        clearError() {
            this.elements.$uploadArea.find('.error-message').hide();
        },
        
        /**
         * Reset UI do stanu początkowego
         */
        resetToInitialState() {
            // Reset state
            this.state.selectedFile = null;
            this.state.isProcessing = false;
            
            // Reset UI elements
            this.elements.$processButton
                .prop('disabled', true)
                .removeClass('loading')
                .text('Wybierz plik PDF');
            
            // Reset upload area
            this.elements.$uploadArea
                .removeClass('file-selected success-state')
                .html(`
                    <div class="upload-icon">📁</div>
                    <div class="upload-text">Przeciągnij PDF lub kliknij aby wybrać</div>
                    <div class="upload-subtext">Maksymalny rozmiar: 50MB</div>
                `);
            
            // Ukrycie progress bar
            this.elements.$progressBar.hide();
            this.elements.$progressFill.css('width', '0%');
            
            // Reset input file
            this.elements.$fileInput.val('');
            
            console.log('PDFMaster: UI zresetowane do stanu początkowego');
        },
        
        /**
         * Anulowanie bieżącej operacji
         */
        cancelOperation() {
            if (this.state.currentXHR) {
                this.state.currentXHR.abort();
                console.log('PDFMaster: Operacja anulowana przez użytkownika');
            }
        },
        
        /**
         * Ładowanie informacji o kredytach użytkownika
         */
        loadCreditsInfo() {
            // Skip if already processing to avoid conflicts
            if (this.state.isProcessing) {
                return;
            }

            $.ajax({
                url: this.config.ajax_url,
                type: 'POST',
                data: {
                    action: 'pdfmaster_check_credits',
                    nonce: this.config.nonce
                },
                timeout: 10000 // 10 second timeout
            })
            .done((response) => {
                if (response.success && response.data) {
                    this.updateCreditsDisplay(response.data.remaining_credits);
                } else {
                    console.warn('PDFMaster: Nieprawidłowa odpowiedź serwera dla kredytów:', response);
                }
            })
            .fail((xhr, status, error) => {
                console.warn('PDFMaster: Nie udało się pobrać informacji o kredytach:', {xhr, status, error});
                // Don't show error to user for credits check - it's not critical
            });
        },
        
        /**
         * Aktualizacja wyświetlania kredytów
         */
        updateCreditsDisplay(remainingCredits) {
            if (this.elements.$creditsInfo.length > 0) {
                this.elements.$creditsInfo.text(remainingCredits);
                
                // Ostrzeżenie gdy kredyty się kończą
                if (remainingCredits <= 1) {
                    this.elements.$creditsInfo.closest('.credits-info')
                        .addClass('low-credits')
                        .append('<div class="low-credits-warning">⚠️ Zostało niewiele darmowych operacji</div>');
                }
            }
        },
        
        /**
         * Aktualizacja podglądu kompresji
         */
        updateCompressionPreview() {
            const level = this.state.compressionLevel;
            const descriptions = {
                1: 'Niska kompresja - najlepsza jakość',
                2: 'Średnia kompresja - zbalansowana', 
                3: 'Wysoka kompresja - najmniejszy rozmiar'
            };
            
            const expectedReduction = {
                1: '20-30%',
                2: '40-60%', 
                3: '60-80%'
            };
            
            // Aktualizacja opisu kompresji (jeśli element istnieje)
            const $description = $('.compression-description');
            if ($description.length > 0) {
                $description.html(`
                    <strong>${descriptions[level]}</strong><br>
                    <small>Oczekiwana redukcja: ${expectedReduction[level]}</small>
                `);
            }
        }
    };
    
    // Helper function to safely initialize PDFMaster
    function initializePDFMaster() {
        // Check if PDFMaster elements exist on page and we're not already initialized
        if ($('.pdfmaster-tool-container').length > 0 && !PDFMaster.initialized) {
            console.log('PDFMaster: Attempting initialization...');
            PDFMaster.init();
        } else if (PDFMaster.initialized) {
            console.log('PDFMaster: Already initialized, skipping duplicate init');
        } else {
            console.log('PDFMaster: No PDFMaster containers found on page');
        }
    }

    // Initialize on DOM ready
    $(document).ready(initializePDFMaster);

    // Re-initialize on Elementor frontend changes (for live editing)
    $(window).on('elementor/frontend/init', function() {
        setTimeout(initializePDFMaster, 100);
    });

    // Handle AJAX page changes (for SPA-like WordPress themes)
    $(document).on('DOMContentLoaded', initializePDFMaster);

    // Eksport do globalnego scope dla debugowania (tylko w dev)
    if (typeof window !== 'undefined' && window.location.hostname === 'localhost') {
        window.PDFMaster = PDFMaster;
    }
    
})(jQuery);