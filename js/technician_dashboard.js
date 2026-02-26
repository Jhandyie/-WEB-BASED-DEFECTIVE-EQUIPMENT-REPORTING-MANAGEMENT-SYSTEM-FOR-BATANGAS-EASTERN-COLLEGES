/**
 * Technician Dashboard JavaScript
 * Handles client-side interactions for the technician dashboard
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialize dashboard functionality
    initializeSidebar();
    initializeTaskCards();
    initializeQuickActions();
    initializeSearch();
    initializeNotifications();
});

// Sidebar functionality
function initializeSidebar() {
    const sidebar = document.getElementById('sb');
    const mobileBtn = document.querySelector('.mob-btn');
    
    if (mobileBtn) {
        mobileBtn.addEventListener('click', function() {
            sidebar.classList.toggle('open');
        });
    }
    
    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(e) {
        if (window.innerWidth <= 768) {
            if (!sidebar.contains(e.target) && !mobileBtn.contains(e.target)) {
                sidebar.classList.remove('open');
            }
        }
    });
}

// Task card interactions
function initializeTaskCards() {
    const taskCards = document.querySelectorAll('.task-card');
    
    taskCards.forEach(card => {
        card.addEventListener('click', function(e) {
            // Don't trigger if clicking on button
            if (e.target.classList.contains('tc-btn')) {
                return;
            }
            
            const reportId = this.dataset.reportId;
            if (reportId) {
                window.location.href = 'technician_task_details.php?report_id=' + reportId;
            }
        });
    });
}

// Quick action buttons
function initializeQuickActions() {
    const claimTaskBtn = document.getElementById('claim-task-btn');
    if (claimTaskBtn) {
        claimTaskBtn.addEventListener('click', function() {
            window.location.href = 'technician_tasks.php?action=available';
        });
    }
    
    const viewHistoryBtn = document.getElementById('view-history-btn');
    if (viewHistoryBtn) {
        viewHistoryBtn.addEventListener('click', function() {
            window.location.href = 'technician_history.php';
        });
    }
    
    const updateStatusBtn = document.getElementById('update-status-btn');
    if (updateStatusBtn) {
        updateStatusBtn.addEventListener('click', function() {
            window.location.href = 'technician_tasks.php';
        });
    }
}

// Search functionality
function initializeSearch() {
    const searchInput = document.getElementById('task-search');
    const taskCards = document.querySelectorAll('.task-card');
    
    if (searchInput && taskCards.length > 0) {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            
            taskCards.forEach(card => {
                const equipmentName = card.querySelector('.tc-eq')?.textContent.toLowerCase() || '';
                const location = card.querySelector('.tc-loc')?.textContent.toLowerCase() || '';
                const reportId = card.querySelector('.tc-id')?.textContent.toLowerCase() || '';
                
                if (equipmentName.includes(searchTerm) || 
                    location.includes(searchTerm) || 
                    reportId.includes(searchTerm)) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
            
            // Show/hide empty state
            const visibleCards = document.querySelectorAll('.task-card:not([style*="display: none"])');
            const emptyState = document.querySelector('.empty-state');
            
            if (emptyState) {
                emptyState.style.display = visibleCards.length === 0 ? 'block' : 'none';
            }
        });
    }
}

// Notification panel
function initializeNotifications() {
    const notifBtn = document.querySelector('.tb-btn[data-notifications]');
    const notifPanel = document.getElementById('notification-panel');
    
    if (notifBtn && notifPanel) {
        notifBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            notifPanel.classList.toggle('open');
        });
        
        document.addEventListener('click', function(e) {
            if (!notifPanel.contains(e.target)) {
                notifPanel.classList.remove('open');
            }
        });
    }
}

// Filter tasks by status
function filterTasks(status) {
    const taskCards = document.querySelectorAll('.task-card');
    
    taskCards.forEach(card => {
        if (status === 'all') {
            card.style.display = '';
        } else {
            const cardStatus = card.dataset.status;
            card.style.display = cardStatus === status ? '' : 'none';
        }
    });
}

// Filter tasks by priority
function filterTasksByPriority(priority) {
    const taskCards = document.querySelectorAll('.task-card');
    
    taskCards.forEach(card => {
        if (priority === 'all') {
            card.style.display = '';
        } else {
            const cardPriority = card.dataset.priority;
            card.style.display = cardPriority === priority ? '' : 'none';
        }
    });
}

// Sort tasks
function sortTasks(sortBy) {
    const taskGrid = document.querySelector('.task-grid');
    if (!taskGrid) return;
    
    const cards = Array.from(document.querySelectorAll('.task-card'));
    
    cards.sort((a, b) => {
        if (sortBy === 'date-asc') {
            return new Date(a.dataset.date) - new Date(b.dataset.date);
        } else if (sortBy === 'date-desc') {
            return new Date(b.dataset.date) - new Date(a.dataset.date);
        } else if (sortBy === 'priority') {
            const priorityOrder = { 'critical': 0, 'high': 1, 'medium': 2, 'low': 3 };
            return priorityOrder[a.dataset.priority] - priorityOrder[b.dataset.priority];
        }
    });
    
    cards.forEach(card => taskGrid.appendChild(card));
}

// Show toast notification
function showToast(message, type = 'info') {
    let tray = document.querySelector('.ttray');
    if (!tray) {
        tray = document.createElement('div');
        tray.className = 'ttray';
        document.body.appendChild(tray);
    }
    
    const toast = document.createElement('div');
    toast.className = `tst ${type}`;
    toast.innerHTML = `
        <i class="fas fa-${type === 'ok' ? 'check-circle' : type === 'err' ? 'exclamation-circle' : 'info-circle'}"></i>
        <div class="tst-t">${message}</div>
    `;
    
    tray.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'tIn .22s ease reverse';
        setTimeout(() => toast.remove(), 200);
    }, 3000);
}

// Refresh dashboard data
function refreshDashboard() {
    showToast('Refreshing data...', 'info');
    
    fetch('api/technician_dashboard_api.php?action=refresh', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            showToast(data.message || 'Failed to refresh data', 'err');
        }
    })
    .catch(error => {
        showToast('Error refreshing data', 'err');
        console.error('Refresh error:', error);
    });
}

// Export tasks to CSV
function exportTasks() {
    const taskCards = document.querySelectorAll('.task-card');
    let csv = 'Report ID,Equipment,Location,Priority,Status,Date\n';
    
    taskCards.forEach(card => {
        const reportId = card.querySelector('.tc-id')?.textContent || '';
        const equipment = card.querySelector('.tc-eq')?.textContent || '';
        const location = card.querySelector('.tc-loc')?.textContent || '';
        const priority = card.dataset.priority || '';
        const status = card.dataset.status || '';
        const date = card.dataset.date || '';
        
        csv += `"${reportId}","${equipment}","${location}","${priority}","${status}","${date}"\n`;
    });
    
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `technician_tasks_${new Date().toISOString().split('T')[0]}.csv`;
    a.click();
    window.URL.revokeObjectURL(url);
    
    showToast('Tasks exported successfully', 'ok');
}

// Initialize keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Ctrl/Cmd + R to refresh
    if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
        e.preventDefault();
        refreshDashboard();
    }
    
    // Ctrl/Cmd + E to export
    if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
        e.preventDefault();
        exportTasks();
    }
});
