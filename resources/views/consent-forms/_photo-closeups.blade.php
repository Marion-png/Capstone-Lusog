{{--
    What the page sends the consent reader (ConsentFormScanner) for a photographed
    Sulat-Pahibalo: the whole sheet upright, and close-ups of it. Cut in the
    browser because the server has no image library. Nothing keeps them, and if
    they cannot be made the reader still has the photo itself.

    A phone often saves a photo sideways with a note saying which way is up. The
    browser honours that note, so the sheet is drawn out as the adviser sees it
    rather than trusting whoever reads the file next to do the same. `turn` is the
    further quarter-turns the reader asked for when the sheet itself lay sideways.

    A model gives one image the same number of tokens whatever its size, and the
    sheet's service rows sit about 1% of its height apart, so read whole a tick's
    tail is counted as a tick on the row above. Each close-up gets its own
    allowance.

    Upright, they are the left of the sheet, where the boxes sit, in overlapping
    bands from top to bottom. A sideways photo keeps the whole short side, since
    which edge is the left cannot be known from here.
--}}
<script>
window.consentPhotoCloseups = window.consentPhotoCloseups || (async (file, body, turn = 0) => {
    const bands = [[0, 0.34], [0.22, 0.56], [0.44, 0.78], [0.66, 1]];
    const leftOfSheet = 0.72;
    const longestSide = 1600;
    const sheetSide = 2400;
    // Bigger than any of them needs, and small enough for a phone's canvas.
    const pageSide = 4000;

    const jpeg = (canvas) => new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.9));

    body.delete('sheet');
    body.delete('closeups[]');

    try {
        const photo = await createImageBitmap(file);
        const fit = Math.min(1, pageSide / Math.max(photo.width, photo.height));
        const width = Math.round(photo.width * fit);
        const height = Math.round(photo.height * fit);
        const sideways = turn === 90 || turn === 270;

        const page = document.createElement('canvas');
        page.width = sideways ? height : width;
        page.height = sideways ? width : height;
        const pen = page.getContext('2d');
        pen.translate(page.width / 2, page.height / 2);
        pen.rotate((turn * Math.PI) / 180);
        pen.drawImage(photo, -width / 2, -height / 2, width, height);
        photo.close();

        const canvas = document.createElement('canvas');
        const draw = (sx, sy, sw, sh, limit) => {
            const scale = Math.min(1, limit / Math.max(sw, sh));
            canvas.width = Math.round(sw * scale);
            canvas.height = Math.round(sh * scale);
            canvas.getContext('2d').drawImage(page, sx, sy, sw, sh, 0, 0, canvas.width, canvas.height);
        };

        draw(0, 0, page.width, page.height, sheetSide);
        const sheet = await jpeg(canvas);
        if (sheet) body.set('sheet', sheet, 'sheet.jpg');

        const upright = page.height >= page.width;

        for (const [index, [from, to]] of bands.entries()) {
            draw(
                upright ? 0 : page.width * from,
                upright ? page.height * from : 0,
                upright ? page.width * leftOfSheet : page.width * (to - from),
                upright ? page.height * (to - from) : page.height,
                longestSide,
            );

            const closeup = await jpeg(canvas);
            if (closeup) body.append('closeups[]', closeup, 'closeup-' + index + '.jpg');
        }
    } catch (_err) {
        body.delete('sheet');
        body.delete('closeups[]');
    }
});
</script>
