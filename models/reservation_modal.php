<!-- Equipment Reservation Modal -->
<div id="reservationModal" class="modal">
    <div class="modal-content modal-large">
        <div class="modal-header">
            <h2><i class="fas fa-calendar-check"></i> Reserve Equipment</h2>
            <button class="modal-close" onclick="closeReservationModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form id="reservationForm">
            <div class="modal-body">
                <!-- Step Indicator -->
                <div class="step-indicator">
                    <div class="step active" data-step="1">
                        <div class="step-number">1</div>
                        <div class="step-label">Select Equipment</div>
                    </div>
                    <div class="step-line"></div>
                    <div class="step" data-step="2">
                        <div class="step-number">2</div>
                        <div class="step-label">Choose Dates</div>
                    </div>
                    <div class="step-line"></div>
                    <div class="step" data-step="3">
                        <div class="step-number">3</div>
                        <div class="step-label">Details</div>
                    </div>
                </div>

                <!-- Step 1: Equipment Selection -->
                <div class="form-step active" data-step="1">
                    <h3><i class="fas fa-box"></i> Select Equipment</h3>
                    
                    <!-- Search and Filter -->
                    <div class="form-group">
                        <label for="equipment_search">
                            <i class="fas fa-search"></i> Search Equipment
                        </label>
                        <input 
                            type="text" 
                            id="equipment_search" 
                            class="form-control"
                            placeholder="Search by name, category, or location..."
                            oninput="filterEquipment()"
                        >
                    </div>

                    <!-- Category Filter -->
                    <div class="form-group">
                        <label for="category_filter">
                            <i class="fas fa-filter"></i> Filter by Category
                        </label>
                        <select id="category_filter" class="form-control" onchange="filterEquipment()">
                            <option value="">All Categories</option>
                        </select>
                    </div>

                    <!-- Equipment Grid -->
                    <div class="equipment-selection-grid" id="equipmentGrid">
                        <div class="loading-state">
                            <i class="fas fa-spinner fa-spin"></i>
                            <p>Loading equipment...</p>
                        </div>
                    </div>

                    <!-- Selected Equipment Display -->
                    <div id="selectedEquipmentDisplay" class="selected-equipment" style="display: none;">
                        <div class="selected-header">
                            <strong><i class="fas fa-check-circle"></i> Selected Equipment:</strong>
                        </div>
                        <div class="selected-content" id="selectedEquipmentContent"></div>
                    </div>
                </div>

                <!-- Step 2: Date Selection -->
                <div class="form-step" data-step="2" style="display: none;">
                    <h3><i class="fas fa-calendar-alt"></i> Choose Reservation Dates</h3>

                    <!-- Equipment Details Card -->
                    <div class="equipment-details">
                        <div class="detail-card">
                            <div class="detail-row">
                                <span class="detail-label">
                                    <i class="fas fa-box"></i> Equipment Name
                                </span>
                                <span class="detail-value" id="detail_equipment_name">-</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">
                                    <i class="fas fa-tag"></i> Category
                                </span>
                                <span class="detail-value" id="detail_equipment_category">-</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">
                                    <i class="fas fa-map-marker-alt"></i> Location
                                </span>
                                <span class="detail-value" id="detail_equipment_location">-</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">
                                    <i class="fas fa-hashtag"></i> Asset Tag
                                </span>
                                <span class="detail-value" id="detail_equipment_asset_tag">-</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">
                                    <i class="fas fa-check-circle"></i> Status
                                </span>
                                <span class="detail-value text-success" id="detail_equipment_status">Available</span>
                            </div>
                        </div>
                    </div>

                    <!-- Equipment Summary -->
                    <div class="reservation-summary">
                        <div class="summary-item">
                            <i class="fas fa-box"></i>
                            <div>
                                <strong id="summary_equipment_name"></strong>
                                <small id="summary_equipment_category"></small>
                            </div>
                        </div>
                        <div class="summary-item">
                            <i class="fas fa-map-marker-alt"></i>
                            <div>
                                <strong>Location</strong>
                                <small id="summary_equipment_location"></small>
                            </div>
                        </div>
                        <div class="summary-item">
                            <i class="fas fa-box-open"></i>
                            <div>
                                <strong>Available</strong>
                                <small id="summary_equipment_available"></small>
                            </div>
                        </div>
                    </div>

                    <!-- Date Range -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="reservation_date">
                                <i class="fas fa-calendar"></i> Start Date *
                            </label>
                            <input 
                                type="date" 
                                id="reservation_date" 
                                name="reservation_date" 
                                class="form-control"
                                required
                                min=""
                            >
                            <small class="form-help">When do you need the equipment?</small>
                        </div>

                        <div class="form-group">
                            <label for="return_date">
                                <i class="fas fa-calendar"></i> End Date *
                            </label>
                            <input 
                                type="date" 
                                id="return_date" 
                                name="return_date" 
                                class="form-control"
                                required
                                min=""
                            >
                            <small class="form-help">When will you return it?</small>
                        </div>
                    </div>

                    <!-- Duration Display -->
                    <div id="durationDisplay" class="duration-info" style="display: none;"></div>

                    <!-- Calendar Availability Preview -->
                    <div class="availability-calendar" id="availabilityCalendar" style="display: none;">
                        <h4><i class="fas fa-calendar-alt"></i> Availability Calendar</h4>
                        <div class="calendar-legend">
                            <span class="legend-item">
                                <span class="legend-color available"></span> Available
                            </span>
                            <span class="legend-item">
                                <span class="legend-color reserved"></span> Reserved
                            </span>
                            <span class="legend-item">
                                <span class="legend-color selected"></span> Your Selection
                            </span>
                        </div>
                        <div id="calendarView"></div>
                    </div>

                    <!-- Quantity -->
                    <div class="form-group">
                        <label for="quantity">
                            <i class="fas fa-sort-numeric-up"></i> Quantity Needed *
                        </label>
                        <div class="quantity-selector">
                            <button type="button" class="qty-btn" onclick="decrementQuantity()">
                                <i class="fas fa-minus"></i>
                            </button>
                            <input 
                                type="number" 
                                id="quantity" 
                                name="quantity" 
                                class="form-control qty-input"
                                min="1" 
                                value="1" 
                                required
                                readonly
                            >
                            <button type="button" class="qty-btn" onclick="incrementQuantity()">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                        <small class="form-help" id="quantityHelp">Number of units needed</small>
                    </div>
                </div>

                <!-- Step 3: Purpose & Confirmation -->
                <div class="form-step" data-step="3" style="display: none;">
                    <h3><i class="fas fa-clipboard-list"></i> Reservation Details</h3>

                    <!-- Purpose -->
                    <div class="form-group">
                        <label for="purpose">
                            <i class="fas fa-clipboard-list"></i> Purpose of Reservation *
                        </label>
                        <textarea 
                            id="purpose" 
                            name="purpose" 
                            class="form-control"
                            rows="5" 
                            placeholder="Please describe the purpose of this reservation...&#10;&#10;Examples:&#10;- Class lecture (CS101)&#10;- Laboratory experiment&#10;- Student event&#10;- Research project"
                            required
                            maxlength="500"
                        ></textarea>
                        <small class="form-help">
                            <span id="purposeCharCount">0</span>/500 characters
                        </small>
                    </div>

                    <!-- Contact Information -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="contact_person">
                                <i class="fas fa-user"></i> Contact Person *
                            </label>
                            <input 
                                type="text" 
                                id="contact_person" 
                                name="contact_person" 
                                class="form-control"
                                placeholder="Your full name"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="contact_number">
                                <i class="fas fa-phone"></i> Contact Number *
                            </label>
                            <input 
                                type="tel" 
                                id="contact_number" 
                                name="contact_number" 
                                class="form-control"
                                placeholder="09XX-XXX-XXXX"
                                required
                                pattern="[0-9]{4}-[0-9]{3}-[0-9]{4}"
                            >
                            <small class="form-help">Format: 09XX-XXX-XXXX</small>
                        </div>
                    </div>

                    <!-- Department/Course -->
                    <div class="form-group">
                        <label for="department">
                            <i class="fas fa-building"></i> Department/Course
                        </label>
                        <input 
                            type="text" 
                            id="department" 
                            name="department" 
                            class="form-control"
                            placeholder="e.g., Computer Science, Engineering"
                        >
                    </div>

                    <!-- Special Instructions -->
                    <div class="form-group">
                        <label for="special_instructions">
                            <i class="fas fa-info-circle"></i> Special Instructions (Optional)
                        </label>
                        <textarea 
                            id="special_instructions" 
                            name="special_instructions" 
                            class="form-control"
                            rows="3" 
                            placeholder="Any special requirements or setup needed?"
                            maxlength="300"
                        ></textarea>
                        <small class="form-help">
                            <span id="instructionsCharCount">0</span>/300 characters
                        </small>
                    </div>

                    <!-- Terms and Conditions -->
                    <div class="terms-section">
                        <h4><i class="fas fa-file-contract"></i> Terms and Conditions</h4>
                        <div class="terms-content">
                            <ul>
                                <li>Equipment must be returned on or before the specified return date</li>
                                <li>You are responsible for any damage to the equipment during your reservation period</li>
                                <li>Late returns may result in penalties or suspension of borrowing privileges</li>
                                <li>Equipment must be used only for the stated purpose</li>
                                <li>Report any damage or malfunction immediately to the admin</li>
                                <li>Reservations are subject to approval by the equipment manager</li>
                            </ul>
                        </div>
                        <div class="checkbox-group">
                            <input type="checkbox" id="accept_terms" required>
                            <label for="accept_terms">
                                I have read and agree to the terms and conditions *
                            </label>
                        </div>
                    </div>

                    <!-- Reservation Summary -->
                    <div class="final-summary">
                        <h4><i class="fas fa-clipboard-check"></i> Reservation Summary</h4>
                        <div class="summary-grid">
                            <div class="summary-row">
                                <span class="summary-label">Equipment:</span>
                                <span class="summary-value" id="final_equipment"></span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">Quantity:</span>
                                <span class="summary-value" id="final_quantity"></span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">Start Date:</span>
                                <span class="summary-value" id="final_start_date"></span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">End Date:</span>
                                <span class="summary-value" id="final_end_date"></span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">Duration:</span>
                                <span class="summary-value" id="final_duration"></span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">Purpose:</span>
                                <span class="summary-value" id="final_purpose"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Availability Check Result -->
                <div id="availabilityResult" class="availability-result" style="display: none;"></div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="prevStepBtn" onclick="previousStep()" style="display: none;">
                    <i class="fas fa-arrow-left"></i> Previous
                </button>
                <button type="button" class="btn btn-secondary" onclick="closeReservationModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" class="btn btn-primary" id="nextStepBtn" onclick="nextStep()">
                    Next <i class="fas fa-arrow-right"></i>
                </button>
                <button type="submit" class="btn btn-primary" id="submitReservationBtn" style="display: none;">
                    <i class="fas fa-paper-plane"></i> Submit Reservation
                </button>
            </div>
        </form>
    </div>
</div>

<style>
/* Modal Base Styles */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 10000 !important;
    opacity: 0;
    transition: opacity 0.3s;
}

.modal.active {
    display: block;
    opacity: 1;
}

.modal-content {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%) perspective(1000px) rotateY(10deg) scale(0.95);
    background: white;
    border-radius: 20px;
    max-width: 900px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    opacity: 0;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}

.modal.active .modal-content {
    transform: translate(-50%, -50%) perspective(1000px) rotateY(0deg) scale(1);
    opacity: 1;
}

/* Form Controls */
.form-group {
    margin-bottom: 1.5rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 600;
    color: #374151;
}

.form-control {
    width: 100%;
    padding: 0.75rem;
    border: 2px solid #e5e7eb;
    border-radius: 0.5rem;
    font-size: 1rem;
    transition: all 0.3s;
    font-family: inherit;
}

.form-control:focus {
    outline: none;
    border-color: #7c2d12;
    box-shadow: 0 0 0 3px rgba(124, 45, 18, 0.1);
}

.form-help {
    display: block;
    margin-top: 0.25rem;
    font-size: 0.875rem;
    color: #6b7280;
}

/* Buttons */
.btn {
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: 0.5rem;
    cursor: pointer;
    font-size: 1rem;
    font-weight: 600;
    transition: all 0.3s;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    text-decoration: none;
}

.btn-primary {
    background: linear-gradient(135deg, #7c2d12 0%, #991b1b 100%);
    color: white;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(124, 45, 18, 0.3);
}

.btn-secondary {
    background: #f3f4f6;
    color: #374151;
}

.btn-secondary:hover {
    background: #e5e7eb;
}

.modal-header {
    padding: 1.5rem 2rem;
    background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
    color: white;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-radius: 20px 20px 0 0;
}

.modal-close {
    background: none;
    border: none;
    color: white;
    font-size: 1.5rem;
    cursor: pointer;
    padding: 0.5rem;
    border-radius: 50%;
    transition: background 0.3s;
}

.modal-close:hover {
    background: rgba(255, 255, 255, 0.2);
}

.modal-body {
    padding: 2rem;
}

.modal-footer {
    padding: 1.5rem 2rem;
    background: var(--light);
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-radius: 0 0 20px 20px;
}

/* Modal Large Size */
.modal-large {
    max-width: 900px;
}

/* Step Indicator */
.step-indicator {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 2rem;
    padding: 1.5rem;
    background: var(--light);
    border-radius: 0.75rem;
}

.step {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
    flex: 1;
    position: relative;
}

.step-number {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #e5e7eb;
    color: #9ca3af;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    transition: all 0.3s;
}

.step.active .step-number {
    background: var(--primary);
    color: white;
    transform: scale(1.1);
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
}

.step.completed .step-number {
    background: var(--secondary);
    color: white;
}

.step.completed .step-number::before {
    content: "\f00c";
    font-family: "Font Awesome 6 Free";
    font-weight: 900;
}

.step-label {
    font-size: 0.85rem;
    color: #6b7280;
    font-weight: 500;
    text-align: center;
}

.step.active .step-label {
    color: var(--primary);
    font-weight: 600;
}

.step-line {
    flex: 1;
    height: 2px;
    background: #e5e7eb;
    margin: 0 1rem;
    margin-top: -1.5rem;
}

.step.active ~ .step-line {
    background: var(--primary);
}

/* Form Steps */
.form-step {
    animation: fadeInUp 0.3s;
}

.form-step h3 {
    color: var(--dark);
    margin-bottom: 1.5rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid var(--border);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Equipment Selection Grid */
.equipment-selection-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 1rem;
    max-height: 400px;
    overflow-y: auto;
    padding: 0.5rem;
    margin: 1rem 0;
}

.equipment-card {
    border: 2px solid var(--border);
    border-radius: 0.75rem;
    padding: 1rem;
    cursor: pointer;
    transition: all 0.3s;
    background: white;
}

.equipment-card:hover {
    border-color: var(--primary);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.equipment-card.selected {
    border-color: var(--primary);
    background: linear-gradient(135deg, rgba(79, 70, 229, 0.05) 0%, rgba(79, 70, 229, 0.1) 100%);
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.2);
}

.equipment-card.selected::before {
    content: "\f00c";
    font-family: "Font Awesome 6 Free";
    font-weight: 900;
    position: absolute;
    top: 0.5rem;
    right: 0.5rem;
    background: var(--primary);
    color: white;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
}

.equipment-card {
    position: relative;
}

.equipment-card-header {
    display: flex;
    align-items: start;
    gap: 0.75rem;
    margin-bottom: 0.75rem;
}

.equipment-icon {
    width: 50px;
    height: 50px;
    border-radius: 0.5rem;
    background: var(--light);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: var(--primary);
}

.equipment-info h4 {
    margin: 0;
    font-size: 1rem;
    color: var(--dark);
}

.equipment-info small {
    color: #6b7280;
    display: block;
    margin-top: 0.25rem;
}

.equipment-card-body {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.equipment-meta {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.85rem;
    color: #6b7280;
}

.availability-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.25rem 0.75rem;
    border-radius: 1rem;
    font-size: 0.75rem;
    font-weight: 600;
}

.availability-badge.available {
    background: #d1fae5;
    color: #065f46;
}

.availability-badge.limited {
    background: #fef3c7;
    color: #92400e;
}

.availability-badge.unavailable {
    background: #fee2e2;
    color: #991b1b;
}

/* Selected Equipment Display */
.selected-equipment {
    background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
    color: white;
    padding: 1rem;
    border-radius: 0.75rem;
    margin: 1rem 0;
    animation: slideDown 0.3s;
}

.selected-header {
    margin-bottom: 0.75rem;
}

.selected-content {
    display: flex;
    align-items: center;
    gap: 1rem;
}

/* Reservation Summary */
.reservation-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
    padding: 1.5rem;
    background: var(--light);
    border-radius: 0.75rem;
}

.summary-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.summary-item i {
    font-size: 1.5rem;
    color: var(--primary);
}

.summary-item strong {
    display: block;
    color: var(--dark);
    font-size: 0.9rem;
}

.summary-item small {
    display: block;
    color: #6b7280;
    font-size: 0.85rem;
}

/* Quantity Selector */
.quantity-selector {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    max-width: 200px;
}

.qty-btn {
    width: 40px;
    height: 40px;
    border: 2px solid var(--border);
    background: white;
    border-radius: 0.5rem;
    cursor: pointer;
    transition: all 0.3s;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--primary);
    font-size: 1rem;
}

.qty-btn:hover {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

.qty-input {
    flex: 1;
    text-align: center;
    font-weight: 600;
    font-size: 1.1rem;
}

/* Duration Info */
.duration-info {
    background: #eff6ff;
    color: #1e40af;
    padding: 1rem;
    border-radius: 0.75rem;
    margin: 1rem 0;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    border-left: 4px solid #3b82f6;
}

.duration-info i {
    font-size: 1.5rem;
    color: #3b82f6;
}

.duration-info strong {
    font-size: 1.1rem;
}

/* Calendar */
.availability-calendar {
    margin: 1.5rem 0;
    padding: 1.5rem;
    background: var(--light);
    border-radius: 0.75rem;
}

.calendar-legend {
    display: flex;
    gap: 1.5rem;
    margin-bottom: 1rem;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.85rem;
}

.legend-color {
    width: 20px;
    height: 20px;
    border-radius: 0.25rem;
}

.legend-color.available {
    background: #d1fae5;
    border: 1px solid #10b981;
}

.legend-color.reserved {
    background: #fee2e2;
    border: 1px solid #ef4444;
}

.legend-color.selected {
    background: #dbeafe;
    border: 1px solid #3b82f6;
}

/* Terms Section */
.terms-section {
    background: var(--light);
    padding: 1.5rem;
    border-radius: 0.75rem;
    margin: 1.5rem 0;
}

.terms-section h4 {
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--dark);
}

.terms-content {
    background: white;
    padding: 1rem;
    border-radius: 0.5rem;
    max-height: 200px;
    overflow-y: auto;
    margin-bottom: 1rem;
}

.terms-content ul {
    margin: 0;
    padding-left: 1.5rem;
}

.terms-content li {
    margin-bottom: 0.5rem;
    color: #4b5563;
    line-height: 1.6;
}

.checkbox-group {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.checkbox-group input[type="checkbox"] {
    width: 20px;
    height: 20px;
    cursor: pointer;
}

.checkbox-group label {
    margin: 0;
    cursor: pointer;
    user-select: none;
}

/* Final Summary */
.final-summary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 1.5rem;
    border-radius: 0.75rem;
    margin-top: 1.5rem;
}

.final-summary h4 {
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.summary-grid {
    display: grid;
    gap: 0.75rem;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    padding: 0.75rem;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 0.5rem;
}

.summary-label {
    font-weight: 500;
    opacity: 0.9;
}

.summary-value {
    font-weight: 600;
}

/* Availability Result */
.availability-result {
    margin-top: 1rem;
    padding: 1rem;
    border-radius: 0.75rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.availability-result.available {
    background: #d1fae5;
    color: #065f46;
    border-left: 4px solid #10b981;
}

.availability-result.unavailable {
    background: #fee2e2;
    color: #991b1b;
    border-left: 4px solid #ef4444;
}

.availability-result.warning {
    background: #fef3c7;
    color: #92400e;
    border-left: 4px solid #f59e0b;
}

/* Loading State */
.loading-state {
    text-align: center;
    padding: 3rem;
    color: #9ca3af;
}

.loading-state i {
    font-size: 2rem;
    margin-bottom: 1rem;
    display: block;
}

/* Responsive */
@media (max-width: 768px) {
    .modal-large {
        width: 95%;
        max-width: none;
    }

    .step-indicator {
        padding: 1rem;
    }

    .step-label {
        font-size: 0.75rem;
    }

    .step-number {
        width: 35px;
        height: 35px;
        font-size: 0.9rem;
    }

    .equipment-selection-grid {
        grid-template-columns: 1fr;
    }

    .reservation-summary {
        grid-template-columns: 1fr;
    }

    .summary-row {
        flex-direction: column;
        gap: 0.25rem;
    }
}
/* Equipment Details Card */
.equipment-details {
    margin-bottom: 1.5rem;
    animation: slideDown 0.3s;
}

.detail-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    padding: 1.5rem;
    border-radius: 0.75rem;
    color: white;
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.2);
}

.detail-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.5rem 0;
    border-bottom: 1px solid rgba(255, 255, 255, 0.2);
}

.detail-row:last-child {
    border-bottom: none;
}

.detail-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    opacity: 0.9;
}

.detail-value {
    font-weight: 600;
}

.text-success {
    color: #10b981;
}

.text-danger {
    color: #ef4444;
}

/* Form Row (for side-by-side inputs) */
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

@media (max-width: 768px) {
    .form-row {
        grid-template-columns: 1fr;
    }
}

/* Date Input Styling */
input[type="date"] {
    width: 100%;
    padding: 0.75rem;
    border: 2px solid var(--border);
    border-radius: 0.5rem;
    font-size: 1rem;
    transition: all 0.3s;
    font-family: inherit;
}

input[type="date"]:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
}

/* Number Input Styling */
input[type="number"] {
    width: 100%;
    padding: 0.75rem;
    border: 2px solid var(--border);
    border-radius: 0.5rem;
    font-size: 1rem;
    transition: all 0.3s;
}

input[type="number"]:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
}

/* Availability Check */
.availability-check {
    background: var(--light);
    padding: 1rem;
    border-radius: 0.5rem;
    margin-top: 1rem;
}

.check-loading {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--primary);
    font-weight: 500;
}

.availability-available {
    background: #d1fae5;
    color: #065f46;
    padding: 1rem;
    border-radius: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-top: 1rem;
    border-left: 4px solid #10b981;
}

.availability-unavailable {
    background: #fee2e2;
    color: #991b1b;
    padding: 1rem;
    border-radius: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-top: 1rem;
    border-left: 4px solid #ef4444;
}

.availability-warning {
    background: #fef3c7;
    color: #92400e;
    padding: 1rem;
    border-radius: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-top: 1rem;
    border-left: 4px solid #f59e0b;
}

/* Duration Display */
.duration-info {
    background: #eff6ff;
    color: #1e40af;
    padding: 0.75rem;
    border-radius: 0.5rem;
    margin-top: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.9rem;
}

.duration-info i {
    color: #3b82f6;
}



