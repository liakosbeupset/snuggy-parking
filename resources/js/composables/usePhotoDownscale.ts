/**
 * Shrink a camera photo to at most `maxEdge` px as JPEG before upload. Any decode or encode
 * failure returns the original file; the server's size and type rules still apply (FM14).
 */
export async function downscale(
    file: File,
    maxEdge = 1600,
    quality = 0.8,
): Promise<File> {
    try {
        const bitmap = await createImageBitmap(file);
        const scale = Math.min(
            1,
            maxEdge / Math.max(bitmap.width, bitmap.height),
        );
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);

        const context = canvas.getContext('2d');

        if (!context) {
            bitmap.close();

            return file;
        }

        context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        bitmap.close();

        const blob = await new Promise<Blob | null>((resolve) =>
            canvas.toBlob(resolve, 'image/jpeg', quality),
        );

        if (!blob) {
            return file;
        }

        const name = file.name.replace(/\.[^.]*$/, '') || 'photo';

        return new File([blob], `${name}.jpg`, { type: 'image/jpeg' });
    } catch {
        return file;
    }
}
