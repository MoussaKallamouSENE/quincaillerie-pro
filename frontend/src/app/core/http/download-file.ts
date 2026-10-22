/**
 * Déclenche le téléchargement d'un fichier dans le navigateur.
 *
 * Le contenu est exposé par une URL temporaire, attachée à un lien
 * `<a download>` cliqué par programme. L'URL est libérée dès le clic : le
 * navigateur a déjà commencé à lire les octets du fichier.
 *
 * @param blob Contenu du fichier.
 * @param filename Nom proposé dans la boîte de téléchargement.
 */
export function downloadBlob(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob);

  try {
    const link = document.createElement('a');

    link.href = url;
    link.download = filename;
    link.rel = 'noopener';

    // Le lien doit être dans le document pour que le clic soit pris en compte
    // par certains navigateurs.
    document.body.append(link);
    link.click();
    link.remove();
  } finally {
    URL.revokeObjectURL(url);
  }
}
