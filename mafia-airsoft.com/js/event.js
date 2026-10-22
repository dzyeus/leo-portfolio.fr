// Interactions spécifiques pour la page événement
document.addEventListener('DOMContentLoaded', function() {
    // Confirmation avant inscription
    const teamButtons = document.querySelectorAll('.btn-team');
    teamButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            if (!this.disabled) {
                const teamName = this.textContent.trim().split('\n')[0];
                const form = this.closest('form');
                const select = form ? form.querySelector('.quantity-select') : null;
                const quantity = select ? parseInt(select.value, 10) : 1;
                const price = form ? parseInt(form.dataset.price || '0', 10) : 0;
                let message = quantity > 1
                    ? `Réserver ${quantity} places dans ${teamName} ?`
                    : `Voulez-vous vraiment rejoindre ${teamName} ?`;
                if (price > 0) {
                    const total = (price * quantity / 100).toFixed(2).replace('.', ',');
                    message += `\n\nTotal : ${total} € – tu vas être redirigé vers la page de paiement sécurisée.`;
                }
                if (!confirm(message)) {
                    e.preventDefault();
                }
            }
        });
    });
    
    // Afficher le nombre de places restantes en temps réel
    updateTeamAvailability();
});

function updateTeamAvailability() {
    const teamStats = document.querySelectorAll('.team-stat');
    teamStats.forEach(stat => {
        const countText = stat.querySelector('.team-count');
        if (countText) {
            const [current, max] = countText.textContent.split('/').map(n => parseInt(n.trim()));
            const remaining = max - current;
            
            if (remaining === 0) {
                stat.style.opacity = '0.6';
            } else if (remaining <= 3) {
                const statusEl = stat.querySelector('.team-status');
                if (statusEl) {
                    statusEl.style.animation = 'pulse 2s infinite';
                }
            }
        }
    });
}

// Animation pulse pour les places limitées
const style = document.createElement('style');
style.textContent = `
    @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.6; }
    }
`;
document.head.appendChild(style);
