class HawaiianDocumentReviewer {
    constructor() {
        this.documents = [];
        this.filteredDocuments = []; // Documents filtered by selected group
        this.currentIndex = 0;
        this.currentSourceId = null;
        this.currentGroup = ''; // Currently selected group
        this.viewMode = 'text'; // 'text', 'sentences', or 'html'
        this.touchStartX = 0;
        this.touchStartY = 0;
        this.isDragging = false;
        this.mouseDownTime = 0; // Track when mouse was pressed
        this.minSwipeDistance = 50;
        this.sliderDebounceTimer = null; // Timer for slider debouncing
        this.isNavigatingToSourceId = false; // Flag to prevent group change interference
        
        // Store bound event handlers for removal/re-addition
        this.boundHandleGroupChange = (e) => this.handleGroupChange(e);
        
        this.init();
    }

    async init() {
        // Initialize DOM elements first
        this.initElements();
        
        // Show loading overlay now that elements are initialized
        this.showLoadingOverlay(true);
        
        // Bind events
        this.bindEvents();
        
        // Load documents
        await this.loadDocuments();
        
        // Setup group selector
        this.setupGroupSelector();
        
        // Handle URL parameters and load appropriate document
        await this.handleUrlParameters();
        
        this.showLoadingOverlay(false);
    }

    initElements() {
        this.elements = {
            documentTitle: document.getElementById('document-title'),
            groupSelector: document.getElementById('group-selector'),
            documentSourceId: document.getElementById('document-sourceid'),
            documentContent: document.getElementById('document-content'),
            documentContainer: document.getElementById('document-container'),
            sourceidInput: document.getElementById('sourceid-input'),
            sourceidSlider: document.getElementById('sourceid-slider'),
            sliderMinLabel: document.getElementById('slider-min-label'),
            sliderMaxLabel: document.getElementById('slider-max-label'),
            typeaheadDropdown: document.getElementById('typeahead-dropdown'),
            prevBtn: document.getElementById('prev-btn'),
            nextBtn: document.getElementById('next-btn'),
            viewToggleBtn: document.getElementById('view-toggle-btn'),
            progressText: document.getElementById('progress-text'),
            loadingOverlay: document.getElementById('loading-overlay')
        };
    }

    bindEvents() {
        // Navigation buttons
        this.elements.prevBtn.addEventListener('click', () => this.navigatePrevious());
        this.elements.nextBtn.addEventListener('click', () => this.navigateNext());

        // View toggle button
        this.elements.viewToggleBtn.addEventListener('click', () => this.toggleView());

        // Group selector
        this.elements.groupSelector.addEventListener('change', this.boundHandleGroupChange);

        // Sourceid slider
        this.elements.sourceidSlider.addEventListener('input', (e) => this.handleSliderInput(e));
        this.elements.sourceidSlider.addEventListener('change', (e) => this.handleSliderChange(e));

        // Source ID input and typeahead
        this.elements.sourceidInput.addEventListener('input', (e) => this.handleTypeahead(e));
        this.elements.sourceidInput.addEventListener('keydown', (e) => this.handleInputKeydown(e));
        this.elements.sourceidInput.addEventListener('blur', () => this.hideTypeahead());

        // Touch/swipe events for navigation area only
        const navArea = document.querySelector('.navigation');
        if (navArea) {
            navArea.addEventListener('touchstart', (e) => this.handleTouchStart(e), { passive: true });
            navArea.addEventListener('touchmove', (e) => this.handleTouchMove(e), { passive: false });
            navArea.addEventListener('touchend', (e) => this.handleTouchEnd(e), { passive: true });

            // Mouse events for desktop swipe simulation in navigation area
            navArea.addEventListener('mousedown', (e) => this.handleMouseDown(e));
            navArea.addEventListener('mousemove', (e) => this.handleMouseMove(e));
            navArea.addEventListener('mouseup', (e) => this.handleMouseUp(e));
            navArea.addEventListener('mouseleave', (e) => this.handleMouseUp(e));
            
            // Prevent context menu only when dragging in navigation area
            navArea.addEventListener('contextmenu', (e) => {
                if (this.isDragging) {
                    e.preventDefault();
                }
            });
        }

        // Keyboard navigation
        document.addEventListener('keydown', (e) => this.handleKeydown(e));
    }

    async loadDocuments() {
        try {
            //const response = await fetch('https://noiiolelo.org/api.php/sources?details');
            const response = await fetch('https://noiiolelo.worldspot.org/api.php/sources?details');
            if (!response.ok) throw new Error('Failed to fetch documents');
            
            const data = await response.json();
            this.documents = data.sources || [];
            
            // Sort by sourceid for consistent navigation
            this.documents.sort((a, b) => parseInt(a.sourceid) - parseInt(b.sourceid));
            
            // Initialize with all documents (no group filter)
            this.filteredDocuments = [...this.documents];
            
            this.updateProgressText();
            
            console.log(`Loaded ${this.documents.length} documents`);
        } catch (error) {
            console.error('Error loading documents:', error);
            this.showError('Failed to load document list. Please check your connection.');
        }
    }

    async handleUrlParameters() {
        const urlParams = new URLSearchParams(window.location.search);
        const sourceId = urlParams.get('sourceid');
        const groupName = urlParams.get('group');
        
        console.log(`URL parameters - sourceid: ${sourceId}, group: ${groupName}`);
        
        if (sourceId) {
            // Source ID takes precedence, but preserve group if specified and compatible
            console.log(`Attempting to navigate to source ID: ${sourceId}`);
            const success = await this.navigateToSourceId(sourceId, groupName);
            console.log(`Navigation to source ID ${sourceId} ${success ? 'succeeded' : 'failed'}`);
            if (!success && this.filteredDocuments.length > 0) {
                // If sourceid not found, load first document
                console.log(`Source ID ${sourceId} not found, loading first document`);
                await this.loadDocument(0);
            }
        } else if (groupName) {
            // Filter by group and load first document
            console.log(`Filtering by group: ${groupName}`);
            this.elements.groupSelector.value = groupName;
            this.filterDocumentsByGroup(groupName);
            if (this.filteredDocuments.length > 0) {
                await this.loadDocument(0);
            }
        } else {
            // No URL parameters, load first document
            console.log(`No URL parameters, loading first document`);
            if (this.filteredDocuments.length > 0) {
                await this.loadDocument(0);
            }
        }
    }

    updateUrlParameters() {
        const url = new URL(window.location);
        
        // Always include sourceid if we have a current document
        if (this.currentSourceId) {
            url.searchParams.set('sourceid', this.currentSourceId);
        }
        
        // Include group if a specific group is selected (not "All Groups")
        if (this.currentGroup) {
            url.searchParams.set('group', this.currentGroup);
        } else {
            url.searchParams.delete('group');
        }
        
        // Update URL without triggering page reload
        window.history.replaceState({}, '', url);
    }

    setupGroupSelector() {
        // Get unique group names
        const groups = [...new Set(this.documents.map(doc => doc.groupname || 'Unknown'))].sort();
        
        // Clear and populate selector
        this.elements.groupSelector.innerHTML = '<option value="">All Groups</option>';
        groups.forEach(group => {
            const option = document.createElement('option');
            option.value = group;
            option.textContent = group;
            this.elements.groupSelector.appendChild(option);
        });
        
        // Initialize slider
        this.updateSlider();
    }

    filterDocumentsByGroup(groupName) {
        if (!groupName) {
            // Show all documents
            this.filteredDocuments = [...this.documents];
        } else {
            // Filter by group
            this.filteredDocuments = this.documents.filter(doc => 
                (doc.groupname || 'Unknown') === groupName
            );
        }
        
        // Sort filtered documents by sourceid
        this.filteredDocuments.sort((a, b) => parseInt(a.sourceid) - parseInt(b.sourceid));
        
        this.currentGroup = groupName;
        this.updateProgressText();
        this.updateSlider();
    }

    updateSlider() {
        if (this.filteredDocuments.length === 0) {
            this.elements.sourceidSlider.min = 0;
            this.elements.sourceidSlider.max = 0;
            this.elements.sourceidSlider.value = 0;
            this.elements.sliderMinLabel.textContent = '-';
            this.elements.sliderMaxLabel.textContent = '-';
            return;
        }

        // Set slider range based on current filtered documents
        this.elements.sourceidSlider.min = 0;
        this.elements.sourceidSlider.max = this.filteredDocuments.length - 1;
        this.elements.sourceidSlider.value = this.currentIndex;
        
        // Update labels with actual sourceids
        const minSourceId = this.filteredDocuments[0]?.sourceid || '-';
        const maxSourceId = this.filteredDocuments[this.filteredDocuments.length - 1]?.sourceid || '-';
        this.elements.sliderMinLabel.textContent = minSourceId;
        this.elements.sliderMaxLabel.textContent = maxSourceId;
    }

    handleSliderInput(event) {
        // Clear any existing timer
        if (this.sliderDebounceTimer) {
            clearTimeout(this.sliderDebounceTimer);
        }
        
        // Update UI immediately for responsive feel (but don't update currentIndex yet)
        const newIndex = parseInt(event.target.value);
        if (newIndex >= 0 && newIndex < this.filteredDocuments.length) {
            // Get the document at the new index to show its sourceid
            const document = this.filteredDocuments[newIndex];
            const sourceid = document ? document.sourceid : '';
            const groupText = this.currentGroup ? ` in "${this.currentGroup}"` : '';
            this.elements.progressText.textContent = 
                `Document ${newIndex + 1} of ${this.filteredDocuments.length}${groupText}${sourceid ? ` (${sourceid})` : ''}`;
            
            // Update navigation buttons based on new position
            this.elements.prevBtn.disabled = newIndex <= 0;
            this.elements.nextBtn.disabled = newIndex >= this.filteredDocuments.length - 1;
        }
        
        // Set up debounced document loading
        this.sliderDebounceTimer = setTimeout(async () => {
            const finalIndex = parseInt(event.target.value);
            if (finalIndex !== this.currentIndex && finalIndex >= 0 && finalIndex < this.filteredDocuments.length) {
                await this.loadDocument(finalIndex);
            }
        }, 500);
    }

    async handleSliderChange(event) {
        // Clear any pending debounced calls since we're handling this immediately
        if (this.sliderDebounceTimer) {
            clearTimeout(this.sliderDebounceTimer);
            this.sliderDebounceTimer = null;
        }
        
        const newIndex = parseInt(event.target.value);
        if (newIndex !== this.currentIndex && newIndex >= 0 && newIndex < this.filteredDocuments.length) {
            await this.loadDocument(newIndex);
        }
    }

    async handleGroupChange(event) {
        const selectedGroup = event.target.value;
        this.filterDocumentsByGroup(selectedGroup);
        
        // Load first document in the filtered set
        if (this.filteredDocuments.length > 0) {
            await this.loadDocument(0);
        } else {
            this.showError('No documents found in the selected group.');
        }
        
        // Update URL to reflect group selection
        this.updateUrlParameters();
    }

    async loadDocument(index) {
        if (index < 0 || index >= this.filteredDocuments.length) return;

        console.log(`loadDocument called with index ${index}`);
        console.log(`Current filtered documents length: ${this.filteredDocuments.length}`);
        console.log(`Document at index ${index}:`, this.filteredDocuments[index]);
        
        // Add stack trace to see what's calling loadDocument
        console.trace('loadDocument call stack');

        this.currentIndex = index;
        const document = this.filteredDocuments[index];
        this.currentSourceId = document.sourceid;

        // Update UI immediately with document metadata
        this.updateDocumentInfo(document);
        this.updateNavigationButtons();
        this.updateProgressText();
        this.updateSlider();
        this.updateUrlParameters();

        // Show loading state for content
        this.elements.documentContent.innerHTML = '<div class="loading">Loading document content...</div>';

        try {
            if (this.viewMode === 'sentences') {
                // Fetch sentences data
                const response = await fetch(`https://noiiolelo.worldspot.org/api.php/source/${document.sourceid}/sentences`);
                if (!response.ok) throw new Error('Failed to fetch sentences data');
                
                const data = await response.json();
                const sentences = data.sentences || [];
                
                // Display sentences with metadata
                this.displaySentences(sentences);
            } else if (this.viewMode === 'html') {
                // Fetch HTML content
                const response = await fetch(`https://noiiolelo.worldspot.org/api.php/source/${document.sourceid}/html`);
                if (!response.ok) throw new Error('Failed to fetch HTML content');
                
                const data = await response.json();
                const htmlContent = data.html || '<div class="empty-state">No HTML content available</div>';
                
                // Display HTML content with smooth transition
                this.elements.documentContent.classList.add('transitioning');
                setTimeout(() => {
                    this.elements.documentContent.innerHTML = htmlContent;
                    this.elements.documentContent.classList.remove('transitioning');
                    this.elements.documentContent.scrollTop = 0;
                }, 150);
            } else {
                // Fetch plain text document content
                const response = await fetch(`https://noiiolelo.worldspot.org/api.php/source/${document.sourceid}/plain`);
                if (!response.ok) throw new Error('Failed to fetch document content');
                
                const data = await response.json();
                const content = data.text || 'No content available';
                
                // Display content with smooth transition
                this.elements.documentContent.classList.add('transitioning');
                setTimeout(() => {
                    // Replace newlines with <br/> tags for proper display
                    const htmlContent = content.replace(/\n/g, '<br/>');
                    this.elements.documentContent.innerHTML = htmlContent;
                    this.elements.documentContent.classList.remove('transitioning');
                    this.elements.documentContent.scrollTop = 0;
                }, 150);
            }

        } catch (error) {
            console.error('Error loading document content:', error);
            this.elements.documentContent.innerHTML = '<div class="error">Failed to load document content. Please try again.</div>';
        }
    }

    displaySentences(sentences) {
        if (!sentences || sentences.length === 0) {
            this.elements.documentContent.innerHTML = '<div class="empty-state">No sentences available for this document.</div>';
            return;
        }

        let html = '';
        sentences.forEach((sentence, index) => {
            const metadata = [];
            
            // Get all properties except text and position, sort alphabetically
            const excludedKeys = ['text', 'position'];
            const availableKeys = Object.keys(sentence)
                .filter(key => !excludedKeys.includes(key))
                .sort(); // Alphabetical order
            
            // Add all available metadata fields
            availableKeys.forEach(key => {
                const value = sentence[key];
                if (value !== undefined && value !== null) {
                    // Format the key name (capitalize first letter, replace underscores with spaces)
                    const formattedKey = key.charAt(0).toUpperCase() + key.slice(1).replace(/_/g, ' ');
                    
                    // Format the value based on type
                    let formattedValue;
                    if (typeof value === 'number') {
                        // Round numbers to 3 decimal places if they have decimals
                        formattedValue = value % 1 === 0 ? value.toString() : Number(value).toFixed(3);
                    } else {
                        formattedValue = value.toString();
                    }
                    
                    metadata.push(`<strong>${formattedKey}:</strong> ${formattedValue}`);
                }
            });
            
            html += `
                <div class="sentence-item">
                    <div class="sentence-content">${sentence.position !== undefined ? sentence.position : (index + 1)}: ${sentence.text || 'No text available'}</div>
                    <div class="sentence-metadata">${metadata.join(' | ')}</div>
                </div>
            `;
        });

        this.elements.documentContent.classList.add('transitioning');
        setTimeout(() => {
            this.elements.documentContent.innerHTML = html;
            this.elements.documentContent.classList.remove('transitioning');
            this.elements.documentContent.scrollTop = 0;
        }, 150);
    }

    toggleView() {
        // Cycle through: text -> sentences -> html -> text
        if (this.viewMode === 'text') {
            this.viewMode = 'sentences';
        } else if (this.viewMode === 'sentences') {
            this.viewMode = 'html';
        } else {
            this.viewMode = 'text';
        }
        
        // Update button text and style
        this.elements.viewToggleBtn.classList.remove('sentences-mode', 'html-mode');
        
        if (this.viewMode === 'sentences') {
            this.elements.viewToggleBtn.textContent = '📝 Sentences';
            this.elements.viewToggleBtn.classList.add('sentences-mode');
        } else if (this.viewMode === 'html') {
            this.elements.viewToggleBtn.textContent = '🌐 HTML';
            this.elements.viewToggleBtn.classList.add('html-mode');
        } else {
            this.elements.viewToggleBtn.textContent = '📄 Full Text';
        }
        
        // Reload current document with new view
        if (this.filteredDocuments.length > 0 && this.currentIndex >= 0) {
            this.loadDocument(this.currentIndex);
        }
    }

    updateDocumentInfo(document) {
        this.elements.documentTitle.textContent = document.title || document.sourcename || 'Untitled';
        // Always update group selector to match the current document's group
        this.elements.groupSelector.value = this.currentGroup || '';
        this.elements.documentSourceId.textContent = document.sourceid || '-';
    }

    updateNavigationButtons() {
        this.elements.prevBtn.disabled = this.currentIndex <= 0;
        this.elements.nextBtn.disabled = this.currentIndex >= this.filteredDocuments.length - 1;
    }

    updateProgressText() {
        if (this.filteredDocuments.length === 0) {
            this.elements.progressText.textContent = 'No documents available';
        } else {
            const currentDocument = this.filteredDocuments[this.currentIndex];
            const sourceid = currentDocument ? currentDocument.sourceid : '';
            const groupText = this.currentGroup ? ` in "${this.currentGroup}"` : '';
            this.elements.progressText.textContent = 
                `Document ${this.currentIndex + 1} of ${this.filteredDocuments.length}${groupText}${sourceid ? ` (${sourceid})` : ''}`;
        }
    }

    async navigatePrevious() {
        if (this.currentIndex > 0) {
            await this.loadDocument(this.currentIndex - 1);
        }
    }

    async navigateNext() {
        if (this.currentIndex < this.filteredDocuments.length - 1) {
            await this.loadDocument(this.currentIndex + 1);
        }
    }

    async navigateToSourceId(sourceId, preferredGroup = null) {
        console.log(`Navigating to source ID: ${sourceId}, preferred group: ${preferredGroup}`);
        console.log(`Total documents loaded: ${this.documents.length}`);
        console.log(`Current filtered documents: ${this.filteredDocuments.length}`);
        
        // Convert sourceId to number for comparison (API returns numbers)
        const targetSourceId = parseInt(sourceId);
        
        // If a preferred group is specified, filter to that group first
        if (preferredGroup) {
            console.log(`Filtering to preferred group: ${preferredGroup}`);
            
            // Temporarily remove the event listener to prevent interference
            this.elements.groupSelector.removeEventListener('change', this.boundHandleGroupChange);
            
            // Switch to the preferred group
            this.elements.groupSelector.value = preferredGroup;
            this.filterDocumentsByGroup(preferredGroup);
            
            // Re-add the event listener
            this.elements.groupSelector.addEventListener('change', this.boundHandleGroupChange);
            
            // Now check if the document exists in this preferred group
            let index = this.filteredDocuments.findIndex(doc => parseInt(doc.sourceid) === targetSourceId);
            if (index !== -1) {
                console.log(`Found source ID ${sourceId} in preferred group ${preferredGroup} at index ${index}`);
                await this.loadDocument(index);
                return true;
            } else {
                console.log(`Source ID ${sourceId} not found in preferred group ${preferredGroup}`);
                // Find the document in all documents to see what group it actually belongs to
                const document = this.documents.find(doc => parseInt(doc.sourceid) === targetSourceId);
                if (document) {
                    const actualGroup = document.groupname || 'Unknown';
                    console.log(`Source ID ${sourceId} actually belongs to group: ${actualGroup}, switching from preferred group ${preferredGroup}`);
                    
                    // Switch to the document's actual group
                    this.elements.groupSelector.removeEventListener('change', this.boundHandleGroupChange);
                    this.elements.groupSelector.value = actualGroup;
                    this.filterDocumentsByGroup(actualGroup);
                    this.elements.groupSelector.addEventListener('change', this.boundHandleGroupChange);
                    
                    // Find the document in the newly filtered set
                    index = this.filteredDocuments.findIndex(doc => parseInt(doc.sourceid) === targetSourceId);
                    if (index !== -1) {
                        console.log(`Found source ID ${sourceId} in actual group ${actualGroup} at index ${index}`);
                        await this.loadDocument(index);
                        return true;
                    }
                } else {
                    console.log(`Source ID ${sourceId} not found in any documents`);
                }
            }
        } else {
            // No preferred group specified, use existing logic
            
            // Find in current filtered documents first
            let index = this.filteredDocuments.findIndex(doc => parseInt(doc.sourceid) === targetSourceId);
            if (index !== -1) {
                console.log(`Found source ID ${sourceId} in current filtered documents at index ${index}`);
                await this.loadDocument(index);
                return true;
            }
            
            // If not found in filtered documents, find in all documents
            const document = this.documents.find(doc => parseInt(doc.sourceid) === targetSourceId);
            if (document) {
                const documentGroup = document.groupname || 'Unknown';
                console.log(`Found source ID ${sourceId} in document group: ${documentGroup}`);
                
                // Temporarily remove the event listener to prevent interference
                this.elements.groupSelector.removeEventListener('change', this.boundHandleGroupChange);
                
                // Switch to the document's group
                this.elements.groupSelector.value = documentGroup;
                this.filterDocumentsByGroup(documentGroup);
                
                // Re-add the event listener
                this.elements.groupSelector.addEventListener('change', this.boundHandleGroupChange);
                
                // Find the document in the newly filtered set
                index = this.filteredDocuments.findIndex(doc => parseInt(doc.sourceid) === targetSourceId);
                if (index !== -1) {
                    console.log(`Found source ID ${sourceId} in filtered documents at index ${index}`);
                    await this.loadDocument(index);
                    return true;
                }
            } else {
                console.log(`Source ID ${sourceId} not found in any documents`);
                // Let's see what source IDs we do have
                const availableIds = this.documents.map(doc => doc.sourceid).slice(0, 10);
                console.log(`First 10 available source IDs:`, availableIds);
            }
        }
        
        console.log(`Source ID ${sourceId} not found`);
        return false;
    }

    handleTypeahead(event) {
        const query = event.target.value.trim();
        
        if (query.length === 0) {
            this.hideTypeahead();
            return;
        }

        // Filter from all documents (not just filtered ones) for broader search
        const matches = this.documents.filter(doc => {
            const searchText = `${doc.sourceid} ${doc.title || doc.sourcename || ''} ${doc.groupname || ''}`.toLowerCase();
            return searchText.includes(query.toLowerCase());
        }).slice(0, 10); // Limit to 10 results

        if (matches.length > 0) {
            this.showTypeahead(matches);
        } else {
            this.hideTypeahead();
        }
    }

    showTypeahead(matches) {
        const dropdown = this.elements.typeaheadDropdown;
        dropdown.innerHTML = '';

        matches.forEach((doc, index) => {
            const item = document.createElement('div');
            item.className = 'typeahead-item';
            item.innerHTML = `
                <div class="typeahead-item-title">${doc.title || doc.sourcename || 'Untitled'}</div>
                <div class="typeahead-item-details">ID: ${doc.sourceid} | Group: ${doc.groupname || 'Unknown'}</div>
            `;
            
            item.addEventListener('mousedown', (e) => {
                e.preventDefault(); // Prevent input blur
                this.selectTypeaheadItem(doc);
            });

            dropdown.appendChild(item);
        });

        dropdown.style.display = 'block';
    }

    hideTypeahead() {
        setTimeout(() => {
            this.elements.typeaheadDropdown.style.display = 'none';
        }, 150);
    }

    async selectTypeaheadItem(document) {
        this.elements.sourceidInput.value = document.sourceid;
        this.hideTypeahead();
        await this.navigateToSourceId(document.sourceid);
    }

    handleInputKeydown(event) {
        if (event.key === 'Enter') {
            const sourceId = this.elements.sourceidInput.value.trim();
            if (sourceId) {
                this.navigateToSourceId(sourceId);
            }
            this.hideTypeahead();
        } else if (event.key === 'Escape') {
            this.hideTypeahead();
            this.elements.sourceidInput.blur();
        }
    }

    handleKeydown(event) {
        // Only handle if not typing in input
        if (document.activeElement === this.elements.sourceidInput) return;

        switch (event.key) {
            case 'ArrowLeft':
            case 'h':
                event.preventDefault();
                this.navigatePrevious();
                break;
            case 'ArrowRight':
            case 'l':
                event.preventDefault();
                this.navigateNext();
                break;
            case '/':
                event.preventDefault();
                this.elements.sourceidInput.focus();
                break;
        }
    }

    // Touch/Swipe handling
    handleTouchStart(event) {
        if (event.touches.length === 1) {
            this.touchStartX = event.touches[0].clientX;
            this.touchStartY = event.touches[0].clientY;
            this.isDragging = false;
        }
    }

    handleTouchMove(event) {
        if (event.touches.length === 1) {
            const touchX = event.touches[0].clientX;
            const touchY = event.touches[0].clientY;
            const deltaX = Math.abs(touchX - this.touchStartX);
            const deltaY = Math.abs(touchY - this.touchStartY);

            // Determine if this is a horizontal swipe
            if (deltaX > deltaY && deltaX > 10) {
                this.isDragging = true;
                event.preventDefault(); // Prevent scrolling
            }
        }
    }

    handleTouchEnd(event) {
        if (this.isDragging) {
            const touchEndX = event.changedTouches[0].clientX;
            const deltaX = touchEndX - this.touchStartX;

            if (Math.abs(deltaX) > this.minSwipeDistance) {
                if (deltaX > 0) {
                    // Swipe right - previous document
                    this.navigatePrevious();
                } else {
                    // Swipe left - next document
                    this.navigateNext();
                }
            }
        }
        this.isDragging = false;
    }

    // Mouse drag simulation for desktop
    handleMouseDown(event) {
        this.touchStartX = event.clientX;
        this.touchStartY = event.clientY;
        this.isDragging = false;
        this.mouseDownTime = Date.now();
        // Don't disable text selection immediately - wait to see if it's actually a drag
    }

    handleMouseMove(event) {
        if (event.buttons === 1) { // Left mouse button held
            const deltaX = Math.abs(event.clientX - this.touchStartX);
            const deltaY = Math.abs(event.clientY - this.touchStartY);

            // Simple horizontal movement detection for navigation area
            if (deltaX > deltaY && deltaX > 20) {
                this.isDragging = true;
                event.preventDefault();
            }
        }
    }

    handleMouseUp(event) {
        if (this.isDragging) {
            const deltaX = event.clientX - this.touchStartX;

            if (Math.abs(deltaX) > this.minSwipeDistance) {
                if (deltaX > 0) {
                    this.navigatePrevious();
                } else {
                    this.navigateNext();
                }
            }
        }
        
        // Reset drag state
        this.isDragging = false;
    }

    showLoadingOverlay(show) {
        if (show) {
            this.elements.loadingOverlay.classList.remove('hidden');
        } else {
            this.elements.loadingOverlay.classList.add('hidden');
        }
    }

    showError(message) {
        this.elements.documentContent.innerHTML = `<div class="error">${message}</div>`;
    }
}

// Initialize the app when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    new HawaiianDocumentReviewer();
});

// Add some helpful keyboard shortcuts info to console
console.log(`
Hawaiian Document Reviewer - Keyboard Shortcuts:
- Arrow Left/Right or H/L: Navigate between documents
- / (slash): Focus search input
- Enter: Navigate to entered source ID
- Escape: Close search dropdown

Touch/Mouse:
- Swipe left/right in the navigation area to navigate between documents
- Document area allows full text selection without interference

URL Parameters:
- ?sourceid=12345 - Navigate directly to a specific source ID
- ?group=groupname - Filter to a specific group
- ?sourceid=12345&group=groupname - Source ID takes precedence
- URL automatically updates as you navigate to preserve state on refresh
`);
