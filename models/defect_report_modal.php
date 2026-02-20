<!-- Defect Report Modal -->
<div id="defectReportModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-exclamation-triangle"></i> Report Equipment Defect</h2>
            <button class="modal-close" onclick="closeDefectModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form id="defectReportForm" enctype="multipart/form-data">
            <div class="modal-body">
                <!-- Equipment Selection -->
                <div class="form-group">
                    <label for="equipment_id">
                        <i class="fas fa-box"></i> Equipment *
                    </label>
                    <input
                        type="text"
                        id="equipment_id"
                        name="equipment_id"
                        class="form-control"
                        placeholder="e.g., Dell Desktop Computer, Projector Epson EB-X41, Ceiling Fan"
                        required
                        maxlength="255"
                    >
                    <small class="form-help">Enter the name of the equipment with the issue</small>
                </div>

                <!-- Issue Description -->
                <div class="form-group">
                    <label for="issue_description">
                        <i class="fas fa-clipboard-list"></i> Issue Description *
                    </label>
                    <textarea 
                        id="issue_description" 
                        name="issue_description" 
                        rows="5" 
                        placeholder="Describe the problem in detail... Be as specific as possible to help technicians resolve the issue quickly."
                        required
                        maxlength="1000"
                    ></textarea>
                    <small class="form-help">
                        <span id="charCount">0</span>/1000 characters - Please be as detailed as possible
                    </small>
                </div>

                <!-- Location -->
                <div class="form-group">
                    <label for="location">
                        <i class="fas fa-map-marker-alt"></i> Location *
                    </label>
                    <input 
                        type="text" 
                        id="location" 
                        name="location" 
                        class="form-control"
                        placeholder="e.g., Building 1, Room 301, Computer Lab"
                        required
                    >
                    <small class="form-help">Specify the exact location (building and room) where you found the defect</small>
                </div>

                <!-- Photo Upload - Multiple Photos -->
                <div class="form-group">
                    <label for="defect_photos">
                        <i class="fas fa-camera"></i> Photo Documentation
                    </label>
                    <div class="photo-upload-area" id="photoUploadArea">
                        <input 
                            type="file" 
                            id="defect_photos" 
                            name="defect_photos[]" 
                            accept="image/jpeg,image/jpg,image/png,image/gif"
                            multiple
                            onchange="handleMultiplePhotoUpload(this)"
                        >
                        <div class="upload-placeholder" id="uploadPlaceholder">
                            <i class="fas fa-cloud-upload-alt"></i>
                            <p>Click to upload or drag and drop</p>
                            <small>JPG, PNG, GIF (Max 5 photos, 5MB each)</small>
                        </div>
                    </div>
                    <div id="photoPreviewContainer" class="photo-preview-grid"></div>
                    <small class="form-help">Upload photos showing the defect. Photos help technicians diagnose issues faster.</small>
                </div>

                <!-- Equipment Info (Auto-filled from equipment) -->
                <div class="form-group" id="equipmentInfo" style="display: none;">
                    <label>
                        <i class="fas fa-info-circle"></i> Equipment Information
                    </label>
                    <div class="info-box">
                        <div class="info-row">
                            <strong>Category:</strong>
                            <span id="equipmentCategory"></span>
                        </div>
                        <div class="info-row">
                            <strong>Location:</strong>
                            <span id="equipmentLocation"></span>
                        </div>
                        <div class="info-row">
                            <strong>Asset Tag:</strong>
                            <span id="equipmentAssetTag"></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDefectModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn btn-primary" id="submitDefectBtn">
                    <i class="fas fa-paper-plane"></i> Submit Report
                </button>
            </div>
        </form>
    </div>
</div>

<style>
/* Modal Styles */
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    animation: fadeIn 0.3s;
    overflow-y: auto;
}

.modal.active {
    display: flex;
    align-items: center;
    justify-content: center;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.modal-content {
    background: white;
    border-radius: 1rem;
    width: 90%;
    max-width: 600px;
    max-height: 90vh;
    overflow: hidden;
    animation: slideDown 0.3s;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
}

@keyframes slideDown {
    from {
        transform: translateY(-50px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

.modal-header {
    padding: 1.5rem;
    background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
    color: white;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h2 {
    margin: 0;
    font-size: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.modal-close {
    background: rgba(255, 255, 255, 0.2);
    border: none;
    color: white;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s;
    font-size: 1.2rem;
}

.modal-close:hover {
    background: rgba(255, 255, 255, 0.3);
    transform: rotate(90deg);
}

.modal-body {
    padding: 2rem;
    max-height: calc(90vh - 200px);
    overflow-y: auto;
}

.modal-footer {
    padding: 1.5rem;
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: flex-end;
    gap: 1rem;
    background: var(--light);
}

/* Form Styles */
.form-group {
    margin-bottom: 1.5rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 600;
    color: var(--dark);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.form-group select,
.form-group textarea,
.form-group input[type="text"],
.form-group input[type="number"] {
    width: 100%;
    padding: 0.75rem;
    border: 2px solid var(--border);
    border-radius: 0.5rem;
    font-size: 1rem;
    transition: all 0.3s;
    font-family: inherit;
}

.form-group select:focus,
.form-group textarea:focus,
.form-group input:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
}

.form-group textarea {
    resize: vertical;
    min-height: 100px;
}

.form-help {
    display: block;
    margin-top: 0.5rem;
    color: #6b7280;
    font-size: 0.875rem;
}

/* Photo Upload Area */
.photo-upload-area {
    position: relative;
    border: 2px dashed var(--border);
    border-radius: 0.5rem;
    overflow: hidden;
    transition: all 0.3s;
}

.photo-upload-area:hover {
    border-color: var(--primary);
    background: rgba(79, 70, 229, 0.02);
}

.photo-upload-area input[type="file"] {
    position: absolute;
    width: 100%;
    height: 100%;
    opacity: 0;
    cursor: pointer;
    z-index: 2;
}

.upload-placeholder {
    padding: 3rem 2rem;
    text-align: center;
    color: #6b7280;
}

.upload-placeholder i {
    font-size: 3rem;
    color: var(--primary);
    margin-bottom: 1rem;
    display: block;
}

.upload-placeholder p {
    margin: 0.5rem 0;
    font-weight: 600;
    color: var(--dark);
}

/* Multiple Photo Preview Grid */
.photo-preview-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 1rem;
    margin-top: 1rem;
}

.photo-preview-item {
    position: relative;
    border-radius: 0.5rem;
    overflow: hidden;
    border: 2px solid var(--border);
    background: var(--light);
}

.photo-preview-item img {
    width: 100%;
    height: 150px;
    object-fit: cover;
    display: block;
}

.photo-preview-item .remove-photo {
    position: absolute;
    top: 0.5rem;
    right: 0.5rem;
    background: var(--danger);
    color: white;
    border: none;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s;
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
    font-size: 0.9rem;
}

.photo-preview-item .remove-photo:hover {
    background: #dc2626;
    transform: scale(1.1);
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4);
}

.photo-preview-item .photo-number {
    position: absolute;
    bottom: 0.5rem;
    left: 0.5rem;
    background: rgba(0, 0, 0, 0.7);
    color: white;
    padding: 0.25rem 0.5rem;
    border-radius: 0.25rem;
    font-size: 0.75rem;
    font-weight: 600;
}

/* Info Box */
.info-box {
    background: var(--light);
    padding: 1rem;
    border-radius: 0.5rem;
    border-left: 4px solid var(--info);
}

.info-box .info-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.5rem 0;
    border-bottom: 1px solid var(--border);
}

.info-box .info-row:last-child {
    border-bottom: none;
}

.info-box strong {
    color: var(--dark);
    font-weight: 600;
}

.info-box span {
    color: #6b7280;
}

/* Form Control */
.form-control {
    width: 100%;
    padding: 0.75rem;
    border: 2px solid var(--border);
    border-radius: 0.5rem;
    font-size: 1rem;
    transition: all 0.3s;
    font-family: inherit;
}

.form-control:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
}

/* Priority Options */
.form-group select option {
    padding: 0.5rem;
}

/* Loading State */
.btn.loading {
    position: relative;
    color: transparent;
    pointer-events: none;
}

.btn.loading::after {
    content: "";
    position: absolute;
    width: 20px;
    height: 20px;
    top: 50%;
    left: 50%;
    margin-left: -10px;
    margin-top: -10px;
    border: 3px solid rgba(255, 255, 255, 0.3);
    border-top-color: white;
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
}

/* Success Message */
.success-message {
    background: var(--secondary);
    color: white;
    padding: 1rem;
    border-radius: 0.5rem;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    animation: slideDown 0.3s;
}

.error-message {
    background: var(--danger);
    color: white;
    padding: 1rem;
    border-radius: 0.5rem;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    animation: slideDown 0.3s;
}

/* Responsive */
@media (max-width: 768px) {
    .modal-content {
        width: 95%;
        margin: 1rem;
    }

    .modal-body {
        padding: 1.5rem;
    }

    .modal-footer {
        flex-direction: column;
    }

    .modal-footer .btn {
        width: 100%;
    }
}

/* Drag and Drop Effects */
.photo-upload-area.drag-over {
    border-color: var(--primary);
    background: rgba(79, 70, 229, 0.05);
}

.photo-upload-area.drag-over .upload-placeholder i {
    transform: scale(1.1);
}
</style>

<script>
// Global variable to track uploaded files
let uploadedPhotos = [];

// Open defect report modal
function openDefectReportModal() {
    const modal = document.getElementById('defectReportModal');
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
    
    // Load equipment list
    loadEquipmentForReport();
    
    // Reset form
    document.getElementById('defectReportForm').reset();
    clearAllPhotos();
}

// Close defect report modal
function closeDefectModal() {
    const modal = document.getElementById('defectReportModal');
    modal.classList.remove('active');
    document.body.style.overflow = 'auto';
    
    // Reset form
    document.getElementById('defectReportForm').reset();
    clearAllPhotos();
}

// Character counter for description
document.addEventListener('DOMContentLoaded', function() {
    const descriptionField = document.getElementById('issue_description');
    if (descriptionField) {
        descriptionField.addEventListener('input', function() {
            document.getElementById('charCount').textContent = this.value.length;
        });
    }
});

// Handle multiple photo upload
function handleMultiplePhotoUpload(input) {
    const files = Array.from(input.files);
    
    if (!files.length) return;
    
    // Validate total number of photos
    const totalPhotos = uploadedPhotos.length + files.length;
    if (totalPhotos > 5) {
        alert('Maximum 5 photos allowed. Please remove some photos first.');
        input.value = '';
        return;
    }
    
    // Validate each file
    const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    const maxSize = 5242880; // 5MB
    
    for (let file of files) {
        // Validate file type
        if (!allowedTypes.includes(file.type)) {
            alert(`Invalid file type: ${file.name}. Please upload JPG, PNG, or GIF.`);
            input.value = '';
            return;
        }
        
        // Validate file size
        if (file.size > maxSize) {
            alert(`File too large: ${file.name}. Maximum size is 5MB.`);
            input.value = '';
            return;
        }
    }
    
    // Add files to array
    files.forEach(file => {
        uploadedPhotos.push(file);
        displayPhotoPreview(file, uploadedPhotos.length - 1);
    });
    
    // Update placeholder visibility
    updatePlaceholderVisibility();
}

// Display photo preview
function displayPhotoPreview(file, index) {
    const container = document.getElementById('photoPreviewContainer');
    
    const reader = new FileReader();
    reader.onload = function(e) {
        const previewItem = document.createElement('div');
        previewItem.className = 'photo-preview-item';
        previewItem.dataset.index = index;
        
        previewItem.innerHTML = `
            <img src="${e.target.result}" alt="Preview ${index + 1}">
            <button type="button" class="remove-photo" onclick="removePhotoByIndex(${index})">
                <i class="fas fa-times"></i>
            </button>
            <span class="photo-number">Photo ${index + 1}</span>
        `;
        
        container.appendChild(previewItem);
    };
    reader.readAsDataURL(file);
}

// Remove photo by index
function removePhotoByIndex(index) {
    // Remove from array
    uploadedPhotos.splice(index, 1);
    
    // Clear and rebuild preview
    const container = document.getElementById('photoPreviewContainer');
    container.innerHTML = '';
    
    // Rebuild previews with new indices
    uploadedPhotos.forEach((file, idx) => {
        displayPhotoPreview(file, idx);
    });
    
    // Update placeholder visibility
    updatePlaceholderVisibility();
    
    // Clear file input
    document.getElementById('defect_photos').value = '';
}

// Clear all photos
function clearAllPhotos() {
    uploadedPhotos = [];
    document.getElementById('photoPreviewContainer').innerHTML = '';
    document.getElementById('defect_photos').value = '';
    updatePlaceholderVisibility();
}

// Update placeholder visibility
function updatePlaceholderVisibility() {
    const placeholder = document.getElementById('uploadPlaceholder');
    if (uploadedPhotos.length > 0) {
        placeholder.style.display = 'none';
    } else {
        placeholder.style.display = 'block';
    }
}

// Drag and drop handlers
document.addEventListener('DOMContentLoaded', function() {
    const uploadArea = document.getElementById('photoUploadArea');
    
    if (uploadArea) {
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            uploadArea.addEventListener(eventName, preventDefaults, false);
        });
        
        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }
        
        ['dragenter', 'dragover'].forEach(eventName => {
            uploadArea.addEventListener(eventName, () => {
                uploadArea.classList.add('drag-over');
            }, false);
        });
        
        ['dragleave', 'drop'].forEach(eventName => {
            uploadArea.addEventListener(eventName, () => {
                uploadArea.classList.remove('drag-over');
            }, false);
        });
        
        uploadArea.addEventListener('drop', function(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            
            if (files.length > 0) {
                const fileInput = document.getElementById('defect_photos');
                fileInput.files = files;
                handleMultiplePhotoUpload(fileInput);
            }
        }, false);
    }
});

// Handle form submission
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('defectReportForm');
    
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            submitDefectReport();
        });
    }
});

function submitDefectReport() {
    const form = document.getElementById('defectReportForm');
    const submitBtn = document.getElementById('submitDefectBtn');
    
    // Validate equipment selection
    const equipmentId = document.getElementById('equipment_id').value;
    if (!equipmentId) {
        showNotification('error', 'Please select an equipment.');
        return;
    }
    
    // Validate location
    const location = document.getElementById('location').value;
    if (!location.trim()) {
        showNotification('error', 'Please specify the location.');
        return;
    }
    
    // Get form data
    const formData = new FormData();
    formData.append('action', 'submit_report');
    formData.append('equipment_id', equipmentId);
    formData.append('issue_description', document.getElementById('issue_description').value);
    formData.append('location', location);
    
    // Add multiple photos
    uploadedPhotos.forEach((photo, index) => {
        formData.append(`defect_photos[]`, photo);
    });
    
    // Show loading state
    submitBtn.classList.add('loading');
    submitBtn.disabled = true;
    
    // Submit via AJAX
    fetch('api/student_dashboard_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        // Remove loading state
        submitBtn.classList.remove('loading');
        submitBtn.disabled = false;
        
        if (data.success) {
            // Show success message
            showNotification('success', data.message || 'Defect report submitted successfully!');
            
            // Close modal after short delay
            setTimeout(() => {
                closeDefectModal();
                
                // Refresh dashboard data
                if (typeof loadDashboardData === 'function') {
                    loadDashboardData();
                }
                if (typeof loadNotifications === 'function') {
                    loadNotifications();
                }
            }, 2000);
            
            // Reset form
            form.reset();
            clearAllPhotos();
        } else {
            showNotification('error', data.message || 'Failed to submit report. Please try again.');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        submitBtn.classList.remove('loading');
        submitBtn.disabled = false;
        showNotification('error', 'An error occurred. Please try again.');
    });
}

// Show notification
function showNotification(type, message) {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = type === 'success' ? 'success-message' : 'error-message';
    notification.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
        <span>${message}</span>
    `;
    
    // Insert at top of modal body
    const modalBody = document.querySelector('#defectReportModal .modal-body');
    modalBody.insertBefore(notification, modalBody.firstChild);
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
        notification.remove();
    }, 5000);
}

// Close modal on outside click
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('defectReportModal');
    
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeDefectModal();
            }
        });
    }
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('defectReportModal');
        if (modal && modal.classList.contains('active')) {
            closeDefectModal();
        }
    }
});
</script>