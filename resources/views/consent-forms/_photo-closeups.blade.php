{{--
    Close-ups of a photographed Sulat-Pahibalo, cut in the browser for the
    consent reader (ConsentFormScanner).

    A model gives one image the same number of tokens whatever its size, and the
    sheet's service rows sit about 1% of its height apart, so read whole a tick's
    tail is counted as a tick on the row above. Each close-up gets its own
    allowance. They are cut here because the server has no image library, and
    they are a reading aid only: nothing keeps them, and if they cannot be made
    the reader still has the whole sheet.

    Upright, they are the left of the sheet, where the boxes sit, in overlapping
    bands from top to bottom. A sideways photo keeps the whole short side, since
    which edge is the left cannot be known from here.
--}}
<script>
window.consentPhotoCloseups = window.consentPhotoCloseups || (async (file, body) => {
    const bands = [[0, 0.34], [0.22, 0.56], [0.44, 0.78], [0.66, 1]];
    const leftOfSheet = 0.72;
    const longestSide = 1600;

    try {
        const photo = await createImageBitmap(file);
        const upright = photo.height >= photo.width;
        const canvas = document.createElement('canvas');

        for (const [index, [from, to]] of bands.entries()) {
            const sx = upright ? 0 : photo.width * from;
            const sy = upright ? photo.height * from : 0;
            const sw = upright ? photo.width * leftOfSheet : photo.width * (to - from);
            const sh = upright ? photo.height * (to - from) : photo.height;
            const scale = Math.min(1, longestSide / Math.max(sw, sh));

            canvas.width = Math.round(sw * scale);
            canvas.height = Math.round(sh * scale);
            canvas.getContext('2d').drawImage(photo, sx, sy, sw, sh, 0, 0, canvas.width, canvas.height);

            const closeup = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.9));
            if (closeup) body.append('closeups[]', closeup, 'closeup-' + index + '.jpg');
        }

        photo.close();
    } catch (_err) {
        body.delete('closeups[]');
    }
});
</script>
