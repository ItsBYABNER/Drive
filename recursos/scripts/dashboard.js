let currentContextId = null;
let currentContextType = null;
let currentContextName = null;
let currentContextMime = '';
let currentContextVisibility = 'private';
let pendingUploadForm = null;
let droppedFolderPaths = [];

function openModal(name) {
    document.getElementById(name + '-modal').classList.add('show');
}

function closeModal(name) {
    if (name === 'preview') stopPreviewMedia();
    document.getElementById(name + '-modal').classList.remove('show');
}

function confirmUploadDuplicates(event) {
    event.preventDefault();
    const form = event.target;
    const selectedFiles = [
        ...(form.querySelectorAll('input[name="files[]"]')[0]?.files || []),
        ...(form.querySelectorAll('input[name="folder_files[]"]')[0]?.files || [])
    ];
    const existingNames = Array.from(document.querySelectorAll('.item[data-type="file"]')).map(item => {
        const name = item.getAttribute('data-name') || '';
        return name.trim().toLowerCase();
    });
    const selectedNames = [];
    selectedFiles.forEach(file => {
        const filePath = (file.webkitRelativePath || file.name || '').replace(/\\/g, '/');
        const name = filePath.split('/').pop() || file.name || '';
        if (name) {
            selectedNames.push(name.trim().toLowerCase());
        }
    });
    const seenNames = {};
    const hasDuplicateName = selectedNames.some(name => {
        if (!name) return false;
        const count = seenNames[name] ? seenNames[name] + 1 : 1;
        seenNames[name] = count;
        return count > 1;
    }) || selectedNames.some(name => existingNames.includes(name));

    if (!hasDuplicateName) {
        submitUploadForm(form, 'rename');
        return false;
    }

    pendingUploadForm = form;
    openModal('duplicate-upload');
    return false;
}

function submitUploadForm(form, action) {
    if (!form) return;
    const formData = new FormData(form);
    if (action) {
        formData.set('duplicate_action', action);
    }
    droppedFolderPaths.forEach(path => formData.append('folder_paths[]', path));
    formData.set('ajax_upload', '1');

    const progressPanel = document.getElementById('upload-progress');
    const progressBar = document.getElementById('upload-progress-bar');
    const progressStatus = document.getElementById('upload-progress-status');
    const submitButton = form.querySelector('button[type="submit"]');
    if (progressPanel) progressPanel.hidden = false;
    if (progressBar) progressBar.value = 0;
    if (progressStatus) progressStatus.textContent = 'Preparando subida...';
    form.querySelectorAll('button').forEach(button => button.disabled = true);
    if (submitButton) submitButton.textContent = 'Subiendo...';

    const request = new XMLHttpRequest();
    request.open('POST', form.action);
    request.upload.addEventListener('progress', event => {
        if (progressStatus) {
            progressStatus.textContent = event.lengthComputable
                ? 'Subiendo... ' + Math.round(event.loaded / event.total * 100) + '%'
                : 'Subiendo archivo...';
        }
        if (progressBar) {
            if (event.lengthComputable) {
                progressBar.value = Math.round(event.loaded / event.total * 100);
            } else {
                progressBar.removeAttribute('value');
            }
        }
    });
    request.upload.addEventListener('load', () => {
        if (progressStatus) progressStatus.textContent = 'Procesando archivos...';
        if (progressBar) progressBar.removeAttribute('value');
    });
    request.addEventListener('load', () => {
        window.location.reload();
    });
    request.addEventListener('error', () => {
        if (progressStatus) progressStatus.textContent = 'Reintentando la subida...';
        form.submit();
    });
    request.send(formData);
}

function submitUploadWithDuplicateAction(action) {
    if (!pendingUploadForm) return;
    document.getElementById('duplicate-action').value = action;
    closeModal('duplicate-upload');
    submitUploadForm(pendingUploadForm, action);
    pendingUploadForm = null;
}

const uploadDropzone = document.getElementById('upload-dropzone');
const uploadFilesInput = document.getElementById('upload-files');
const uploadFolderFilesInput = document.querySelector('input[name="folder_files[]"]');
const uploadDropzoneStatus = document.getElementById('upload-dropzone-status');

function updateUploadDropzoneStatus() {
    const count = (uploadFilesInput?.files.length || 0) + (uploadFolderFilesInput?.files.length || 0);
    uploadDropzoneStatus.textContent = count
        ? count + (count === 1 ? ' elemento seleccionado' : ' elementos seleccionados')
        : 'Se pueden subir archivos sueltos o carpetas completas';
}

function readDirectoryEntries(directoryReader) {
    return new Promise(function (resolve, reject) {
        const entries = [];
        function readBatch() {
            directoryReader.readEntries(function (batch) {
                if (batch.length === 0) {
                    resolve(entries);
                    return;
                }
                entries.push(...batch);
                readBatch();
            }, reject);
        }
        readBatch();
    });
}

async function collectDroppedEntry(entry, parentPath, collectedFiles) {
    if (entry.isFile) {
        const file = await new Promise(function (resolve, reject) {
            entry.file(resolve, reject);
        });
        collectedFiles.push({ file: file, path: parentPath ? parentPath + '/' + file.name : file.name });
        return;
    }
    if (!entry.isDirectory) return;

    const directoryPath = parentPath ? parentPath + '/' + entry.name : entry.name;
    const children = await readDirectoryEntries(entry.createReader());
    for (const child of children) {
        await collectDroppedEntry(child, directoryPath, collectedFiles);
    }
}

if (uploadDropzone && uploadFilesInput && uploadFolderFilesInput && uploadDropzoneStatus) {
    [uploadFilesInput, uploadFolderFilesInput].forEach(function (input) {
        input.addEventListener('change', function () {
            if (input === uploadFolderFilesInput) droppedFolderPaths = [];
            updateUploadDropzoneStatus();
        });
    });

    uploadDropzone.addEventListener('dragover', function (event) {
        event.preventDefault();
        uploadDropzone.classList.add('is-dragging');
        if (event.dataTransfer) event.dataTransfer.dropEffect = 'copy';
    });

    uploadDropzone.addEventListener('dragleave', function (event) {
        if (!event.relatedTarget || !uploadDropzone.contains(event.relatedTarget)) {
            uploadDropzone.classList.remove('is-dragging');
        }
    });

    uploadDropzone.addEventListener('drop', async function (event) {
        event.preventDefault();
        uploadDropzone.classList.remove('is-dragging');
        const items = event.dataTransfer ? Array.from(event.dataTransfer.items || []) : [];
        const collectedFiles = [];
        for (const item of items) {
            const entry = item.webkitGetAsEntry ? item.webkitGetAsEntry() : null;
            if (entry) {
                await collectDroppedEntry(entry, '', collectedFiles);
            } else if (item.kind === 'file') {
                const file = item.getAsFile();
                if (file) collectedFiles.push({ file: file, path: file.name });
            }
        }
        if (collectedFiles.length === 0 && event.dataTransfer?.files.length) {
            Array.from(event.dataTransfer.files).forEach(file => collectedFiles.push({ file: file, path: file.name }));
        }
        if (collectedFiles.length === 0) return;

        const transfer = new DataTransfer();
        collectedFiles.forEach(item => transfer.items.add(item.file));
        uploadFilesInput.files = new DataTransfer().files;
        uploadFolderFilesInput.files = transfer.files;
        uploadFolderFilesInput.dispatchEvent(new Event('change', { bubbles: true }));
        droppedFolderPaths = collectedFiles.map(item => item.path);
    });
}

function updatePasswordStrength(value) {
    const fill = document.getElementById('password-meter-fill');
    const label = document.getElementById('password-strength');
    if (!fill || !label) return;
    let score = 0;
    if (value.length >= 8) score++;
    if (/[A-Z]/.test(value)) score++;
    if (/[a-z]/.test(value)) score++;
    if (/\d/.test(value)) score++;
    if (/[^A-Za-z0-9]/.test(value)) score++;
    const levels = ['Muy débil', 'Débil', 'Aceptable', 'Fuerte', 'Muy fuerte'];
    const colors = ['#f87171', '#fb923c', '#facc15', '#4ade80', '#38d6c7'];
    fill.style.width = (score * 20) + '%';
    fill.style.background = colors[Math.max(0, score - 1)] || colors[0];
    label.textContent = value === '' ? 'Usa 8 caracteres o más' : levels[Math.max(0, score - 1)];
    label.style.color = colors[Math.max(0, score - 1)] || 'var(--muted)';
}

function openFolder(id, type, mimeType, name) {
    if (type === 'folder') {
        window.location.href = 'dashboard.php?folder=' + encodeURIComponent(id);
    } else {
        openFilePreview(null, id, mimeType || '', name || 'Archivo');
    }
}

function toggleFolderPassword(form) {
    const isPrivate = form.querySelector('input[name="visibility"]:checked').value === 'private';
    const password = form.querySelector('input[name="folder_password"]');
    const passwordField = form.querySelector('.folder-password-field');
    passwordField.hidden = !isPrivate;
    password.disabled = !isPrivate;
    password.required = isPrivate;
    if (!isPrivate) {
        password.value = '';
    }
}

function openRenameModal(id, name) {
    document.getElementById('rename-id').value = id;
    document.getElementById('rename-name').value = name;
    openModal('rename');
}

function isPreviewable(mimeType, name) {
    return /^(image|video|audio|text)\//.test(mimeType) || mimeType === 'application/pdf' || mimeType === 'application/json' || mimeType === 'application/xml' || isOfficePreviewable(mimeType, name);
}

function getFileExtension(name) {
    const parts = name.toLowerCase().split('.');
    return parts.length > 1 ? parts.pop() : '';
}

function isOfficePreviewable(mimeType, name) {
    const extension = getFileExtension(name || '');
    const officeExtensions = [
        'doc', 'docx', 'dot', 'dotx',
        'xls', 'xlsx', 'xlsm', 'xlt', 'xltx',
        'ppt', 'pptx', 'pps', 'ppsx',
        'csv', 'tsv', 'ods', 'odt', 'odp',
        'rtf', 'txt'
    ];
    const officeMimeTypes = [
        'application/msword',
        'application/vnd.ms-word',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.template',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.openxmlformats-officedocument.presentationml.template',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.oasis.opendocument.presentation',
        'text/csv', 'text/tab-separated-values', 'text/plain', 'application/rtf', 'application/vnd.ms-office'
    ];
    return officeExtensions.includes(extension) || officeMimeTypes.includes((mimeType || '').toLowerCase());
}

function showPreviewMessage(title, text) {
    const message = document.createElement('div');
    message.className = 'preview-unavailable';
    message.innerHTML = '<strong>' + title + '</strong><span>' + text + '</span>';
    document.getElementById('preview-content').appendChild(message);
}

async function renderPdfPreview(source, content) {
    if (!window.pdfjsLib) {
        showPreviewMessage('PDF.js no está disponible', 'Descarga el archivo para abrirlo.');
        return;
    }
    window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    const pdf = await window.pdfjsLib.getDocument(source).promise;
    for (let pageNumber = 1; pageNumber <= pdf.numPages; pageNumber++) {
        const page = await pdf.getPage(pageNumber);
        const viewport = page.getViewport({ scale: 1.25 });
        const canvas = document.createElement('canvas');
        canvas.className = 'preview-pdf-page';
        canvas.width = viewport.width;
        canvas.height = viewport.height;
        content.appendChild(canvas);
        await page.render({ canvasContext: canvas.getContext('2d'), viewport: viewport }).promise;
    }
}

async function renderOfficePreview(source, mimeType, name, content) {
    const response = await fetch(source);
    const buffer = await response.arrayBuffer();
    const extension = getFileExtension(name || '');

    if ((extension === 'doc' || extension === 'docx' || extension === 'rtf' || extension === 'txt' || extension === 'odt') && window.mammoth) {
        const result = await window.mammoth.convertToHtml({
            arrayBuffer: buffer,
            convertImage: window.mammoth.images.imgElement({
                style: 'max-width:100%; height:auto; display:block; margin:12px auto;'
            })
        });
        const article = document.createElement('article');
        article.className = 'preview-document';
        article.innerHTML = result.value || '<p>Este documento no pudo renderizarse con contenido útil.</p>';
        content.appendChild(article);
        return;
    }

    if ((extension === 'csv' || extension === 'tsv' || extension === 'xlsx' || extension === 'xls' || extension === 'xlsm' || extension === 'ods') && window.XLSX) {
        const workbook = window.XLSX.read(buffer, { type: 'array' });
        workbook.SheetNames.forEach(function (sheetName) {
            const heading = document.createElement('h4');
            heading.className = 'preview-sheet-title';
            heading.textContent = sheetName;
            content.appendChild(heading);
            const table = document.createElement('div');
            table.className = 'preview-sheet';
            table.innerHTML = window.XLSX.utils.sheet_to_html(workbook.Sheets[sheetName], {
                editable: false,
                raw: false,
                id: sheetName
            });
            content.appendChild(table);
        });
        return;
    }

    if (extension === 'txt' || extension === 'csv' || extension === 'tsv' || extension === 'md') {
        const text = new TextDecoder('utf-8').decode(buffer);
        const pre = document.createElement('pre');
        pre.className = 'preview-text-file';
        pre.textContent = text;
        content.appendChild(pre);
        return;
    }

    showPreviewMessage('Biblioteca de documentos no disponible', 'Descarga el archivo para abrirlo.');
}

async function openFilePreview(event, id, mimeType, name) {
    if (event) {
        event.preventDefault();
    }

    const source = 'dashboard.php?action=preview&id=' + encodeURIComponent(id);
    const content = document.getElementById('preview-content');
    const title = document.getElementById('preview-title');
    const download = document.getElementById('preview-download');
    title.textContent = name;
    download.href = 'dashboard.php?action=download&id=' + encodeURIComponent(id);
    content.innerHTML = '';

    openModal('preview');
    try {
        if (!isPreviewable(mimeType, name)) {
            showPreviewMessage('Vista previa no disponible', 'Descarga el archivo para abrirlo.');
            return false;
        }
        if (mimeType === 'application/pdf') {
            await renderPdfPreview(source, content);
            return false;
        }
        if (isOfficePreviewable(mimeType, name)) {
            await renderOfficePreview(source, mimeType, name, content);
            return false;
        }

        let element;
        if (mimeType.indexOf('image/') === 0) {
            element = document.createElement('img');
        } else if (mimeType.indexOf('video/') === 0) {
            element = document.createElement('video');
            element.controls = true;
            element.autoplay = true;
            element.playsInline = true;
            const videoSource = document.createElement('source');
            videoSource.src = source;
            videoSource.type = mimeType;
            element.appendChild(videoSource);
        } else if (mimeType.indexOf('audio/') === 0) {
            element = document.createElement('audio');
            element.controls = true;
            element.autoplay = true;
        } else {
            element = document.createElement('iframe');
            element.title = 'Vista previa de ' + name;
        }
        if (mimeType.indexOf('video/') !== 0) {
            element.src = source;
        }
        element.className = 'preview-media';
        content.appendChild(element);
    } catch (error) {
        content.innerHTML = '';
        showPreviewMessage('No se pudo generar la vista previa', 'Descarga el archivo para abrirlo.');
    }
    return false;
}

function stopPreviewMedia() {
    const content = document.getElementById('preview-content');
    content.querySelectorAll('video, audio').forEach(function (media) {
        media.pause();
        media.removeAttribute('src');
        media.querySelectorAll('source').forEach(source => source.removeAttribute('src'));
        media.load();
    });
    content.innerHTML = '';
}

function closePreview() {
    closeModal('preview');
}

function confirmDelete(id, type, visibility) {
    const form = document.getElementById('delete-form');
    const password = document.getElementById('delete-password');
    form.action = 'dashboard.php?action=delete&id=' + id + '&folder=' + document.body.dataset.currentFolder;
    password.type = 'password';
    password.placeholder = 'Contraseña de la carpeta';
    password.required = type === 'folder' && visibility === 'private';
    password.disabled = !password.required;
    password.value = '';
    password.style.display = password.required ? 'block' : 'none';
    openModal('delete');
}

function showContextMenu(event, id, type, name, mimeType, visibility) {
    event.preventDefault();
    currentContextId = id;
    currentContextType = type;
    currentContextName = name;
    currentContextMime = mimeType || '';
    currentContextVisibility = visibility || 'private';
    const menu = document.getElementById('context-menu');
    menu.style.left = event.clientX + 'px';
    menu.style.top = event.clientY + 'px';
    menu.classList.add('show');
}

function hideContextMenu() {
    document.getElementById('context-menu').classList.remove('show');
}

function contextAction(action) {
    hideContextMenu();
    if (!currentContextId) return;
    if (action === 'open') {
        if (currentContextType === 'folder') {
            window.location.href = 'dashboard.php?folder=' + currentContextId;
        } else {
            openFilePreview(null, currentContextId, currentContextMime, currentContextName);
        }
    } else if (action === 'download') {
        const link = document.createElement('a');
        link.href = 'dashboard.php?action=download&id=' + currentContextId;
        link.download = currentContextName || 'archivo';
        link.target = '_blank';
        document.body.appendChild(link);
        link.click();
        link.remove();
    } else if (action === 'rename') {
        openRenameModal(currentContextId, currentContextName);
    } else if (action === 'delete') {
        confirmDelete(currentContextId, currentContextType, currentContextVisibility);
    }
}

document.addEventListener('click', function (event) {
    if (!event.target.closest('.context-menu')) {
        hideContextMenu();
    }
});

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        document.querySelectorAll('.modal').forEach(function (modal) {
            if (modal.id === 'preview-modal') {
                closePreview();
            } else {
                modal.classList.remove('show');
            }
        });
        hideContextMenu();
    }
});

document.querySelectorAll('.modal').forEach(function (modal) {
    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            if (modal.id === 'preview-modal') {
                closePreview();
            } else {
                modal.classList.remove('show');
            }
        }
    });
});
