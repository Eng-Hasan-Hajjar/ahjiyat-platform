const COLORS = ['#a884fa', '#f5c04a', '#e879f9', '#34d399'];

export function burstConfetti(container, { count = 24 } = {}) {
    for (let i = 0; i < count; i++) {
        const piece = document.createElement('span');
        piece.className = 'game-confetti-piece';
        piece.style.setProperty('--confetti-color', COLORS[i % COLORS.length]);
        piece.style.setProperty('--confetti-x', `${(Math.random() - 0.5) * 240}px`);
        piece.style.setProperty('--confetti-rot', `${Math.random() * 720 - 360}deg`);
        piece.style.left = `${45 + Math.random() * 10}%`;
        piece.style.animationDelay = `${Math.random() * 120}ms`;
        container.appendChild(piece);
        piece.addEventListener('animationend', () => piece.remove());
    }
}