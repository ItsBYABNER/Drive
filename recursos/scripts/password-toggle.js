function createPasswordIcon(visible) {
    const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    icon.setAttribute('viewBox', '0 0 24 24');
    icon.setAttribute('fill', 'none');
    icon.setAttribute('stroke', 'currentColor');
    icon.setAttribute('stroke-width', '1.8');
    icon.setAttribute('stroke-linecap', 'round');
    icon.setAttribute('stroke-linejoin', 'round');
    icon.setAttribute('aria-hidden', 'true');

    const eye = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    eye.setAttribute('d', 'M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z');
    icon.appendChild(eye);

    if (visible) {
        const pupil = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
        pupil.setAttribute('cx', '12');
        pupil.setAttribute('cy', '12');
        pupil.setAttribute('r', '3');
        icon.appendChild(pupil);
    } else {
        const slash = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        slash.setAttribute('d', 'm3 3 18 18');
        icon.appendChild(slash);
    }

    return icon;
}

function initializePasswordToggles() {
    document.querySelectorAll('input[type="password"]').forEach(input => {
        const wrapper = document.createElement('span');
        wrapper.className = 'password-toggle-wrap';
        input.parentNode.insertBefore(wrapper, input);
        wrapper.appendChild(input);

        const button = document.createElement('button');
        button.className = 'password-toggle-button';
        button.type = 'button';
        button.setAttribute('aria-label', 'Mostrar contraseña');
        button.setAttribute('aria-pressed', 'false');
        button.title = 'Mostrar contraseña';
        button.appendChild(createPasswordIcon(false));
        button.addEventListener('click', () => {
            const visible = input.type === 'password';
            input.type = visible ? 'text' : 'password';
            button.setAttribute('aria-label', visible ? 'Ocultar contraseña' : 'Mostrar contraseña');
            button.setAttribute('aria-pressed', String(visible));
            button.title = visible ? 'Ocultar contraseña' : 'Mostrar contraseña';
            button.replaceChildren(createPasswordIcon(visible));
        });
        wrapper.appendChild(button);
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializePasswordToggles, { once: true });
} else {
    initializePasswordToggles();
}