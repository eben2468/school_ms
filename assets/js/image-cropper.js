/*
 * Shared profile-image cropper.
 *
 * Any <input type="file" data-cropper> will, on selection, open a modal that
 * lets the user crop the chosen photo to a square (face) before it is queued
 * for upload. The cropped JPEG replaces the file in the input, so the existing
 * server-side upload handling needs no changes.
 *
 * Optional data attributes on the input:
 *   data-crop-preview="#selector"  -> <img> whose src is set to the crop result
 *   data-crop-icon="#selector"     -> element hidden once a preview exists
 *   data-crop-aspect="1"           -> crop aspect ratio (default 1, square)
 */
(function () {
    'use strict';

    var modal, cropImg, cropper, currentInput;

    function buildModal() {
        modal = document.createElement('div');
        modal.id = 'imgCropModal';
        modal.className = 'fixed inset-0 z-[9999] hidden items-center justify-center bg-black/60 p-4';
        modal.innerHTML =
            '<div class="bg-white rounded-xl shadow-2xl w-full max-w-lg overflow-hidden">' +
                '<div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between">' +
                    '<h3 class="text-lg font-semibold text-gray-900"><i class="fas fa-crop-alt mr-2 text-blue-600"></i>Crop Photo</h3>' +
                    '<button type="button" data-crop-cancel class="text-gray-400 hover:text-gray-600"><i class="fas fa-times text-xl"></i></button>' +
                '</div>' +
                '<div class="p-4 bg-gray-900">' +
                    '<div class="max-h-[60vh] flex justify-center">' +
                        '<img id="imgCropTarget" class="block max-w-full" alt="Crop">' +
                    '</div>' +
                '</div>' +
                '<div class="px-5 py-3 border-t border-gray-200 flex items-center justify-between gap-2 bg-gray-50">' +
                    '<div class="flex gap-2">' +
                        '<button type="button" data-crop-zoom-in class="px-3 py-2 rounded-lg bg-gray-200 hover:bg-gray-300 text-gray-700" title="Zoom in"><i class="fas fa-search-plus"></i></button>' +
                        '<button type="button" data-crop-zoom-out class="px-3 py-2 rounded-lg bg-gray-200 hover:bg-gray-300 text-gray-700" title="Zoom out"><i class="fas fa-search-minus"></i></button>' +
                        '<button type="button" data-crop-rotate class="px-3 py-2 rounded-lg bg-gray-200 hover:bg-gray-300 text-gray-700" title="Rotate"><i class="fas fa-redo"></i></button>' +
                    '</div>' +
                    '<div class="flex gap-2">' +
                        '<button type="button" data-crop-cancel class="px-4 py-2 rounded-lg bg-gray-200 hover:bg-gray-300 text-gray-700 font-medium">Cancel</button>' +
                        '<button type="button" data-crop-confirm class="px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-medium"><i class="fas fa-check mr-1"></i>Crop &amp; Use</button>' +
                    '</div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(modal);

        cropImg = modal.querySelector('#imgCropTarget');
        modal.querySelectorAll('[data-crop-cancel]').forEach(function (b) { b.addEventListener('click', closeModal); });
        modal.querySelector('[data-crop-confirm]').addEventListener('click', confirmCrop);
        modal.querySelector('[data-crop-zoom-in]').addEventListener('click', function () { if (cropper) cropper.zoom(0.1); });
        modal.querySelector('[data-crop-zoom-out]').addEventListener('click', function () { if (cropper) cropper.zoom(-0.1); });
        modal.querySelector('[data-crop-rotate]').addEventListener('click', function () { if (cropper) cropper.rotate(90); });
        modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
    }

    function openModal(src) {
        if (!modal) buildModal();
        if (cropper) { cropper.destroy(); cropper = null; }
        cropImg.src = src;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.style.overflow = 'hidden';
        var aspect = parseFloat(currentInput.getAttribute('data-crop-aspect'));
        if (isNaN(aspect) || aspect <= 0) aspect = 1;
        cropper = new Cropper(cropImg, {
            aspectRatio: aspect,
            viewMode: 1,
            autoCropArea: 0.9,
            background: false,
            responsive: true
        });
    }

    function closeModal() {
        if (cropper) { cropper.destroy(); cropper = null; }
        if (modal) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
        document.body.style.overflow = '';
    }

    function updatePreview(input, dataUrl) {
        var psel = input.getAttribute('data-crop-preview');
        var isel = input.getAttribute('data-crop-icon');
        if (psel) {
            var img = document.querySelector(psel);
            if (img) { img.src = dataUrl; img.classList.remove('hidden'); }
        }
        if (isel) {
            var icon = document.querySelector(isel);
            if (icon) icon.classList.add('hidden');
        }
    }

    function confirmCrop() {
        if (!cropper || !currentInput) return;
        var canvas = cropper.getCroppedCanvas({
            width: 512,
            height: 512,
            imageSmoothingQuality: 'high'
        });
        if (!canvas) { closeModal(); return; }
        canvas.toBlob(function (blob) {
            if (!blob) { closeModal(); return; }
            var orig = (currentInput.files[0] && currentInput.files[0].name) || 'photo.jpg';
            var base = orig.replace(/\.[^.]+$/, '') || 'photo';
            var file = new File([blob], base + '.jpg', { type: 'image/jpeg' });
            var dt = new DataTransfer();
            dt.items.add(file);
            // Replacing input.files programmatically does not fire 'change',
            // so this does not re-trigger the cropper.
            currentInput.files = dt.files;
            updatePreview(currentInput, canvas.toDataURL('image/jpeg'));
            closeModal();
        }, 'image/jpeg', 0.92);
    }

    function onChange(e) {
        var input = e.target;
        var file = input.files && input.files[0];
        if (!file || !/^image\//.test(file.type)) return;
        currentInput = input;
        var reader = new FileReader();
        reader.onload = function (ev) { openModal(ev.target.result); };
        reader.readAsDataURL(file);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('input[type="file"][data-cropper]').forEach(function (input) {
            input.addEventListener('change', onChange);
        });
    });
})();
