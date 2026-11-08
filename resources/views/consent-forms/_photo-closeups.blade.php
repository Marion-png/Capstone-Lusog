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

    The close-ups are evenly lit. A form photographed on a desk is rarely lit
    evenly — the phone's own shadow, a hand, a lamp to one side — and a box in a
    shadow is a dark box: beside an empty box in the light it reads as shaded in,
    and in a shadow where every box is grey none of them can be told apart, so the
    reader answered "?" for all of them. So the light falling on the paper is
    measured and divided out: what is left is the paper, white, and what is on it.
    The light is measured at low resolution by taking, around every point, the
    brightest paper within a window several boxes wide (then the darkest of those,
    which puts a shadow's edge back where it was): every pen stroke, printed word
    and shaded box is narrower than that window, so none of them is mistaken for
    the light and none of them is lifted out. The whole sheet is sent as it was
    taken, so the reader always has the photo itself beside them; the page posts
    `lighting=even` only when the close-ups were evened out, and the reader is
    told so only then.
--}}
<script>
window.consentPhotoCloseups = window.consentPhotoCloseups || (async (file, body, turn = 0) => {
    const bands = [[0, 0.34], [0.22, 0.56], [0.44, 0.78], [0.66, 1]];
    const leftOfSheet = 0.72;
    const longestSide = 1600;
    const sheetSide = 2400;
    // Bigger than any of them needs, and small enough for a phone's canvas.
    const pageSide = 4000;
    // The light is measured on a copy this size — about two and a half pixels
    // of a phone photo to one of it, fine enough that the edge of a shadow
    // stays where it is rather than smearing a grey band along it.
    const lightSide = 720;
    // How wide the window is that the light is measured over, as a share of
    // the sheet's short side: about six boxes, so a box shaded in solid —
    // spilling over its outline, even — is still far narrower than it.
    const lightReach = 0.04;

    const jpeg = (canvas) => new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.9));

    const brighter = (a, b) => (a > b ? a : b);
    const darker = (a, b) => (a < b ? a : b);

    // The brightest (or darkest) value within `radius` either way of each
    // point of a line, in one pass whatever the radius (van Herk / Gil-Werman:
    // running maxima forward and back within blocks one window long).
    const sweep = (line, radius, pick, outside) => {
        const size = 2 * radius + 1;
        const length = line.length + 2 * radius;
        const padded = new Float32Array(length).fill(outside);
        padded.set(line, radius);
        const forward = new Float32Array(length);
        const back = new Float32Array(length);
        for (let i = 0; i < length; i++) forward[i] = i % size === 0 ? padded[i] : pick(forward[i - 1], padded[i]);
        for (let i = length - 1; i >= 0; i--) back[i] = (i % size === size - 1 || i === length - 1) ? padded[i] : pick(back[i + 1], padded[i]);
        const out = new Float32Array(line.length);
        for (let i = 0; i < line.length; i++) out[i] = pick(back[i], forward[i + 2 * radius]);
        return out;
    };

    // The same over a square window: along every row, then down every column.
    const spread = (plane, width, height, radius, pick, outside) => {
        const out = new Float32Array(plane.length);
        for (let y = 0; y < height; y++) out.set(sweep(plane.subarray(y * width, (y + 1) * width), radius, pick, outside), y * width);
        const column = new Float32Array(height);
        for (let x = 0; x < width; x++) {
            for (let y = 0; y < height; y++) column[y] = out[y * width + x];
            const swept = sweep(column, radius, pick, outside);
            for (let y = 0; y < height; y++) out[y * width + x] = swept[y];
        }
        return out;
    };

    // How the paper itself is lit, everywhere on the sheet, as a small image.
    const paperLight = (page) => {
        const scale = Math.min(1, lightSide / Math.max(page.width, page.height));
        const width = Math.max(1, Math.round(page.width * scale));
        const height = Math.max(1, Math.round(page.height * scale));
        const light = document.createElement('canvas');
        light.width = width;
        light.height = height;
        const pen = light.getContext('2d', { willReadFrequently: true });
        pen.drawImage(page, 0, 0, width, height);
        const image = pen.getImageData(0, 0, width, height);
        const radius = Math.max(2, Math.round(Math.min(width, height) * lightReach));
        const plane = new Float32Array(width * height);

        for (let channel = 0; channel < 3; channel++) {
            for (let i = 0; i < plane.length; i++) plane[i] = image.data[i * 4 + channel];
            // Brightest, then darkest, over the same window: every mark
            // narrower than it is gone, and a shadow's edge — wider than any
            // window — is put back exactly where it was. Nothing is blurred
            // afterwards, because a blur is what smears that edge into a band.
            const closed = spread(spread(plane, width, height, radius, brighter, -Infinity), width, height, radius, darker, Infinity);
            for (let i = 0; i < plane.length; i++) image.data[i * 4 + channel] = closed[i];
        }

        pen.putImageData(image, 0, 0);
        return { light, scale };
    };

    // Each colour of each pixel over the paper's light at that point: paper
    // comes out white, and a mark comes out exactly as dark against it as it
    // was against the paper around it in the photograph. The only change is
    // that the paper's own grain, within a few per cent of it, is whitened.
    // Nothing is darkened: a steeper curve made the thick printed outlines of
    // a column of empty boxes look filled in, and the reader marked them.
    const curve = new Uint8ClampedArray(1201);
    for (let i = 0; i < curve.length; i++) {
        curve[i] = Math.round(255 * Math.min(1, i / 1000 / 0.96));
    }

    const evenOut = (canvas, paper, sx, sy, sw, sh) => {
        const lit = document.createElement('canvas');
        lit.width = canvas.width;
        lit.height = canvas.height;
        const litPen = lit.getContext('2d', { willReadFrequently: true });
        // Plain linear filling between the light's points: a sharper filter
        // rings at a shadow's edge, which would draw a line there.
        litPen.imageSmoothingEnabled = true;
        litPen.imageSmoothingQuality = 'low';
        litPen.drawImage(paper.light, sx * paper.scale, sy * paper.scale, sw * paper.scale, sh * paper.scale, 0, 0, lit.width, lit.height);

        const pen = canvas.getContext('2d', { willReadFrequently: true });
        const photo = pen.getImageData(0, 0, canvas.width, canvas.height);
        const light = litPen.getImageData(0, 0, lit.width, lit.height).data;
        const pixels = photo.data;
        for (let i = 0; i < pixels.length; i += 4) {
            for (let channel = i; channel < i + 3; channel++) {
                // A floor under the light, so the dark table around the sheet
                // is not blown up into noise.
                const ratio = pixels[channel] / Math.max(light[channel], 24);
                pixels[channel] = curve[Math.min(1200, Math.round(ratio * 1000))];
            }
        }
        pen.putImageData(photo, 0, 0);
    };

    body.delete('sheet');
    body.delete('closeups[]');
    body.delete('lighting');

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
        // A close-up of a small photo — one sent through a chat app arrives
        // about 1,000 pixels wide, with a box some twenty pixels across — is
        // drawn larger, up to twice its size, so a box gets about as many
        // pixels as it would off a full-size photo. Nothing is added that was
        // not there; the reader just gets more of its allowance per box,
        // which is what keeps two stacked rows apart. The sheet is never enlarged.
        const draw = (sx, sy, sw, sh, limit, grow = 1) => {
            const scale = Math.min(grow, limit / Math.max(sw, sh));
            canvas.width = Math.round(sw * scale);
            canvas.height = Math.round(sh * scale);
            const pen = canvas.getContext('2d');
            pen.imageSmoothingEnabled = true;
            pen.imageSmoothingQuality = 'high';
            pen.drawImage(page, sx, sy, sw, sh, 0, 0, canvas.width, canvas.height);
        };
        const enlarge = 2;

        draw(0, 0, page.width, page.height, sheetSide);
        const sheet = await jpeg(canvas);
        if (sheet) body.set('sheet', sheet, 'sheet.jpg');

        // Evening out is a help, never a condition: if it cannot be done the
        // close-ups go as they were taken, and the reader is not told otherwise.
        let paper = null;
        try {
            paper = paperLight(page);
        } catch (_err) {
            paper = null;
        }
        let even = paper !== null;

        const upright = page.height >= page.width;

        for (const [index, [from, to]] of bands.entries()) {
            const region = [
                upright ? 0 : page.width * from,
                upright ? page.height * from : 0,
                upright ? page.width * leftOfSheet : page.width * (to - from),
                upright ? page.height * (to - from) : page.height,
            ];
            draw(...region, longestSide, enlarge);

            if (even) {
                try {
                    evenOut(canvas, paper, ...region);
                } catch (_err) {
                    // One evened close-up beside raw ones would be read as
                    // lit alike, so all of them are cut again as taken.
                    even = false;
                    body.delete('closeups[]');
                    for (const [again, [a, b]] of bands.slice(0, index + 1).entries()) {
                        draw(upright ? 0 : page.width * a, upright ? page.height * a : 0, upright ? page.width * leftOfSheet : page.width * (b - a), upright ? page.height * (b - a) : page.height, longestSide, enlarge);
                        const raw = await jpeg(canvas);
                        if (raw) body.append('closeups[]', raw, 'closeup-' + again + '.jpg');
                    }
                    continue;
                }
            }

            const closeup = await jpeg(canvas);
            if (closeup) body.append('closeups[]', closeup, 'closeup-' + index + '.jpg');
        }

        if (even && body.getAll('closeups[]').length > 0) body.set('lighting', 'even');
    } catch (_err) {
        body.delete('sheet');
        body.delete('closeups[]');
        body.delete('lighting');
    }
});
</script>
