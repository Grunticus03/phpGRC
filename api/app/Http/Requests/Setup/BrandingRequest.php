<?php
declare(strict_types=1);

namespace App\Http\Requests\Setup;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class BrandingRequest extends FormRequest
{
    /** @return array<string,mixed> */
    public function rules(): array
    {
        $themes = [
            'cerulean','cosmo','cyborg','darkly','flatly','journal','litera','lumen','lux','materia','minty',
            'pulse','sandstone','simplex','sketchy','slate','solar','spacelab','superhero','united','yeti'
        ]; // :contentReference[oaicite:7]{index=7}

        return [
            'name'     => ['required', 'string', 'min:1', 'max:60'],
            'theme'    => ['required', Rule::in($themes)],
            'logo_url' => ['nullable', 'url'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}

