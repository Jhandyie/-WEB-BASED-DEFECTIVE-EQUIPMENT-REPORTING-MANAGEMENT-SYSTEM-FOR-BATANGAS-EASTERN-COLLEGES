/**
 * Enhanced Reservation Modal JavaScript
 * Multi-step reservation process with validation and availability checking
 */

// Global variables
let currentStep = 1;
let selectedEquipment = null;
let allEquipment = [];
let maxQuantity = 0;

// Open reservation modal
function openReservationModal() {
    const modal = document.getElementById('reservationModal');
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';

    // Reset to step 1
    currentStep = 1;
    updateStepDisplay();

    // Set minimum dates to today
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('reservation_date').min = today;
    document.getElementById('return_date').min = today;

    // Load equipment list
    loadEquipmentForReservation();

    // Reset form and selected equipment
    document.getElementById('reservationForm').reset();
    selectedEquipment = null;
    document.getElementById('selectedEquipmentDisplay').style.display = 'none';
}

// Make function globally available
window.openReservationModal = openReservationModal;

// Close reservation modal
function closeReservationModal() {
    const modal = document.getElementById('reservationModal');
    modal.classList.remove('active');
    document.body.style.overflow = 'auto';
    
    // Reset form
    document.getElementById('reservationForm').reset();
    selectedEquipment = null;
    currentStep = 1;
    updateStepDisplay();
}

// Load equipment for reservation
function loadEquipmentForReservation() {
    const gridContainer = document.getElementById('equipmentGrid');
    gridContainer.innerHTML = `
        <div class="loading-state">
            <i class="fas fa-spinner fa-spin"></i>
            <p>Loading equipment...</p>
        </div>
    `;
    
    fetch('api/student_dashboard_api.php?action=get_available_equipment')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data.length > 0) {
                allEquipment = data.data;
                populateCategoryFilter();
                displayEquipmentGrid(allEquipment);
            } else {
                gridContainer.innerHTML = `
                    <div class="loading-state">
                        <i class="fas fa-inbox"></i>
                        <p>No equipment available for reservation</p>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error loading equipment:', error);
            gridContainer.innerHTML = `
                <div class="loading-state">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>Error loading equipment. Please try again.</p>
                </div>
            `;
        });
}

// Populate category filter
function populateCategoryFilter() {
    const categoryFilter = document.getElementById('category_filter');
    const categories = [...new Set(allEquipment.map(eq => eq.equipment_category))];
    
    categoryFilter.innerHTML = '<option value="">All Categories</option>';
    categories.forEach(category => {
        const option = document.createElement('option');
        option.value = category;
        option.textContent = category;
        categoryFilter.appendChild(option);
    });
}

// Display equipment grid
function displayEquipmentGrid(equipment) {
    const gridContainer = document.getElementById('equipmentGrid');
    
    if (equipment.length === 0) {
        gridContainer.innerHTML = `
            <div class="loading-state">
                <i class="fas fa-search"></i>
                <p>No equipment found matching your search</p>
            </div>
        `;
        return;
    }
    
    gridContainer.innerHTML = equipment.map(eq => {
        const available = eq.quantity - (eq.reserved_qty || 0);
        const availabilityClass = available > 5 ? 'available' : available > 0 ? 'limited' : 'unavailable';
        const availabilityText = available > 0 ? `${available} available` : 'Not available';
        
        const icon = getEquipmentIcon(eq.equipment_category);
        
        return `
            <div class="equipment-card ${selectedEquipment && selectedEquipment.id === eq.id ? 'selected' : ''}"
                 onclick="selectEquipment(${JSON.stringify(eq).replace(/"/g, '&quot;')})">
                <div class="equipment-card-header">
                    <div class="equipment-icon">
                        <i class="${icon}"></i>
                    </div>
                    <div class="equipment-info">
                        <h4>${escapeHtml(eq.equipment_name)}</h4>
                        <small>${escapeHtml(eq.equipment_category)}</small>
                    </div>
                </div>
                <div class="equipment-card-body">
                    <div class="equipment-meta">
                        <i class="fas fa-map-marker-alt"></i>
                        <span>${escapeHtml(eq.location || 'N/A')}</span>
                    </div>
                    <div class="availability-badge ${availabilityClass}">
                        <i class="fas fa-${available > 0 ? 'check-circle' : 'times-circle'}"></i>
                        ${availabilityText}
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

// Get equipment icon based on category
function getEquipmentIcon(category) {
    const icons = {
        'Air Conditioner': 'fas fa-snowflake',
        'Television': 'fas fa-tv',
        'Fan': 'fas fa-fan',
        'Whiteboard': 'fas fa-chalkboard',
        'Locker': 'fas fa-lock',
        'Office Chair': 'fas fa-chair',
        'Projector': 'fas fa-video',
        'Computer': 'fas fa-desktop',
        'Table': 'fas fa-table',
        'Printer': 'fas fa-print'
    };
    return icons[category] || 'fas fa-box';
}

// Filter equipment
function filterEquipment() {
    const searchTerm = document.getElementById('equipment_search').value.toLowerCase();
    const category = document.getElementById('category_filter').value;
    
    const filtered = allEquipment.filter(eq => {
        const matchesSearch = eq.equipment_name.toLowerCase().includes(searchTerm) ||
                            eq.equipment_category.toLowerCase().includes(searchTerm) ||
                            (eq.location && eq.location.toLowerCase().includes(searchTerm));
        const matchesCategory = !category || eq.equipment_category === category;
        
        return matchesSearch && matchesCategory;
    });
    
    displayEquipmentGrid(filtered);
}

// Select equipment
function selectEquipment(equipment) {
    selectedEquipment = equipment;
    maxQuantity = equipment.quantity - (equipment.reserved_qty || 0);
    
    // Update selected display
    const selectedDisplay = document.getElementById('selectedEquipmentDisplay');
    const selectedContent = document.getElementById('selectedEquipmentContent');
    
    selectedContent.innerHTML = `
        <strong>${escapeHtml(equipment.equipment_name)}</strong>
        <span>|</span>
        <span>${escapeHtml(equipment.equipment_category)}</span>
        <span>|</span>
        <span><i class="fas fa-map-marker-alt"></i> ${escapeHtml(equipment.location || 'N/A')}</span>
    `;
    
    selectedDisplay.style.display = 'block';
    
    // Update all cards
    const cards = document.querySelectorAll('.equipment-card');
    cards.forEach(card => card.classList.remove('selected'));
    event.currentTarget.classList.add('selected');
}

// Step navigation
function updateStepDisplay() {
    // Update step indicator
    document.querySelectorAll('.step').forEach((step, index) => {
        const stepNum = index + 1;
        step.classList.remove('active', 'completed');
        if (stepNum < currentStep) {
            step.classList.add('completed');
        } else if (stepNum === currentStep) {
            step.classList.add('active');
        }
    });
    
    // Update form steps
    document.querySelectorAll('.form-step').forEach((step, index) => {
        step.style.display = (index + 1) === currentStep ? 'block' : 'none';
    });
    
    // Update buttons
    document.getElementById('prevStepBtn').style.display = currentStep > 1 ? 'inline-flex' : 'none';
    document.getElementById('nextStepBtn').style.display = currentStep < 3 ? 'inline-flex' : 'none';
    document.getElementById('submitReservationBtn').style.display = currentStep === 3 ? 'inline-flex' : 'none';
}

function nextStep() {
    if (!validateCurrentStep()) {
        return;
    }
    
    if (currentStep < 3) {
        currentStep++;
        
        // Load data for next step
        if (currentStep === 2) {
            populateStep2Summary();
        } else if (currentStep === 3) {
            populateStep3Summary();
        }
        
        updateStepDisplay();
        
        // Scroll to top of modal
        document.querySelector('#reservationModal .modal-body').scrollTop = 0;
    }
}

function previousStep() {
    if (currentStep > 1) {
        currentStep--;
        updateStepDisplay();
        
        // Scroll to top of modal
        document.querySelector('#reservationModal .modal-body').scrollTop = 0;
    }
}

// Validate current step
function validateCurrentStep() {
    if (currentStep === 1) {
        if (!selectedEquipment) {
            showReservationNotification('error', 'Please select an equipment first.');
            return false;
        }
    } else if (currentStep === 2) {
        const startDate = document.getElementById('reservation_date').value;
        const endDate = document.getElementById('return_date').value;
        const quantity = parseInt(document.getElementById('quantity').value);
        
        if (!startDate || !endDate) {
            showReservationNotification('error', 'Please select both start and end dates.');
            return false;
        }
        
        if (new Date(endDate) < new Date(startDate)) {
            showReservationNotification('error', 'End date must be after start date.');
            return false;
        }
        
        if (quantity < 1 || quantity > maxQuantity) {
            showReservationNotification('error', `Quantity must be between 1 and ${maxQuantity}.`);
            return false;
        }
    }
    
    return true;
}

// Populate step 2 summary
function populateStep2Summary() {
    document.getElementById('summary_equipment_name').textContent = selectedEquipment.equipment_name;
    document.getElementById('summary_equipment_category').textContent = selectedEquipment.equipment_category;
    document.getElementById('summary_equipment_location').textContent = selectedEquipment.location || 'N/A';
    document.getElementById('summary_equipment_available').textContent = `${maxQuantity} units`;
    
    // Set quantity max
    document.getElementById('quantity').max = maxQuantity;
    document.getElementById('quantityHelp').textContent = `Max: ${maxQuantity} units available`;
}

// Populate step 3 summary
function populateStep3Summary() {
    // Auto-fill contact person
    const contactPerson = document.getElementById('contact_person');
    if (!contactPerson.value && typeof userFullName !== 'undefined') {
        contactPerson.value = userFullName;
    }
    
    // Update final summary
    const startDate = document.getElementById('reservation_date').value;
    const endDate = document.getElementById('return_date').value;
    const quantity = document.getElementById('quantity').value;
    const purpose = document.getElementById('purpose').value;
    
    document.getElementById('final_equipment').textContent = selectedEquipment.equipment_name;
    document.getElementById('final_quantity').textContent = `${quantity} unit(s)`;
    document.getElementById('final_start_date').textContent = formatDate(startDate);
    document.getElementById('final_end_date').textContent = formatDate(endDate);
    document.getElementById('final_duration').textContent = calculateDuration(startDate, endDate);
    document.getElementById('final_purpose').textContent = purpose || 'Not specified yet';
}

// Update duration display
function updateDurationDisplay() {
    const startDate = document.getElementById('reservation_date').value;
    const endDate = document.getElementById('return_date').value;
    
    if (startDate && endDate) {
        const duration = calculateDuration(startDate, endDate);
        const durationDisplay = document.getElementById('durationDisplay');
        durationDisplay.innerHTML = `
            <i class="fas fa-clock"></i>
            <div>
                <strong>Duration: ${duration}</strong>
                <br>
                <small>From ${formatDate(startDate)} to ${formatDate(endDate)}</small>
            </div>
        `;
        durationDisplay.style.display = 'flex';
    }
}

// Calculate duration between dates
function calculateDuration(startDate, endDate) {
    const start = new Date(startDate);
    const end = new Date(endDate);
    const diffTime = Math.abs(end - start);
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1; // Include both days
    
    return `${diffDays} day${diffDays !== 1 ? 's' : ''}`;
}

// Format date for display
function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

// Quantity increment/decrement
function incrementQuantity() {
    const input = document.getElementById('quantity');
    const current = parseInt(input.value);
    if (current < maxQuantity) {
        input.value = current + 1;
    }
}

function decrementQuantity() {
    const input = document.getElementById('quantity');
    const current = parseInt(input.value);
    if (current > 1) {
        input.value = current - 1;
    }
}

// Character counters
document.addEventListener('DOMContentLoaded', function() {
    const purposeField = document.getElementById('purpose');
    if (purposeField) {
        purposeField.addEventListener('input', function() {
            document.getElementById('purposeCharCount').textContent = this.value.length;
        });
    }
    
    const instructionsField = document.getElementById('special_instructions');
    if (instructionsField) {
        instructionsField.addEventListener('input', function() {
            document.getElementById('instructionsCharCount').textContent = this.value.length;
        });
    }
    
    // Date change handlers
    const reservationDate = document.getElementById('reservation_date');
    const returnDate = document.getElementById('return_date');
    
    if (reservationDate) {
        reservationDate.addEventListener('change', function() {
            returnDate.min = this.value;
            if (returnDate.value && returnDate.value < this.value) {
                returnDate.value = '';
            }
            updateDurationDisplay();
        });
    }
    
    if (returnDate) {
        returnDate.addEventListener('change', function() {
            updateDurationDisplay();
        });
    }
    
    // Phone number formatting
    const contactNumber = document.getElementById('contact_number');
    if (contactNumber) {
        contactNumber.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length >= 4) {
                value = value.substring(0, 4) + '-' + value.substring(4);
            }
            if (value.length >= 8) {
                value = value.substring(0, 8) + '-' + value.substring(8, 12);
            }
            e.target.value = value;
        });
    }
});

// Handle form submission
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('reservationForm');
    
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            submitReservation();
        });
    }
});

function submitReservation() {
    const submitBtn = document.getElementById('submitReservationBtn');
    
    // Check terms acceptance
    if (!document.getElementById('accept_terms').checked) {
        showReservationNotification('error', 'Please accept the terms and conditions.');
        return;
    }
    
    // Get form data
    const formData = new URLSearchParams();
    formData.append('action', 'create_reservation');
    formData.append('equipment_id', selectedEquipment.id);
    formData.append('reservation_date', document.getElementById('reservation_date').value);
    formData.append('return_date', document.getElementById('return_date').value);
    formData.append('quantity', document.getElementById('quantity').value);
    formData.append('purpose', document.getElementById('purpose').value);
    formData.append('contact_person', document.getElementById('contact_person').value);
    formData.append('contact_number', document.getElementById('contact_number').value);
    formData.append('department', document.getElementById('department').value);
    formData.append('special_instructions', document.getElementById('special_instructions').value);
    
    // Show loading state
    submitBtn.classList.add('loading');
    submitBtn.disabled = true;
    
    // Submit via AJAX
    fetch('api/student_dashboard_api.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        // Remove loading state
        submitBtn.classList.remove('loading');
        submitBtn.disabled = false;
        
        if (data.success) {
            // Show success message
            showReservationNotification('success', data.message || 'Reservation request submitted successfully!');
            
            // Close modal after delay
            setTimeout(() => {
                closeReservationModal();
                
                // Refresh dashboard data
                if (typeof loadDashboardData === 'function') {
                    loadDashboardData();
                }
                if (typeof loadNotifications === 'function') {
                    loadNotifications();
                }
            }, 2000);
        } else {
            showReservationNotification('error', data.message || 'Failed to submit reservation. Please try again.');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        submitBtn.classList.remove('loading');
        submitBtn.disabled = false;
        showReservationNotification('error', 'An error occurred. Please try again.');
    });
}

// Show reservation notification
function showReservationNotification(type, message) {
    const notification = document.createElement('div');
    notification.className = type === 'success' ? 'success-message' : 'error-message';
    notification.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
        <span>${message}</span>
    `;
    
    const modalBody = document.querySelector('#reservationModal .modal-body');
    modalBody.insertBefore(notification, modalBody.firstChild);
    
    setTimeout(() => {
        notification.remove();
    }, 5000);
}

// Utility function
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Close modal on outside click
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('reservationModal');
    
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeReservationModal();
            }
        });
    }
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('reservationModal');
        if (modal && modal.classList.contains('active')) {
            closeReservationModal();
        }
    }
});