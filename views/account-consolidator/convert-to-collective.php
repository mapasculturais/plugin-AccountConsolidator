<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 * @var array $agents
 */

?>
<form method="POST" action="convertToCollective">
<table>
    <thead>
        <tr>
            <td>#usuário</td>
            <td>#agente</td>
            <td>é perfil</td>
            <td>cpf</td>
            <td>cnpj</td>
            <td>&nbsp;</td>
            <td>nome</td>
            <td>nome completo</td>
        </tr>
    </thead>
    <tbody>
        <?php foreach($agents as $agent): ?>
            <tr>
                <td><?=$agent->user_id?></td>
                <td><?=$agent->id?></td>
                <td><?=$agent->id == $agent->profile_id ? 'SIM' : ''?></td>
                <td><?=$agent->cpf ?></td>
                <td><?=$agent->cnpj ?></td>
                <td><input type="checkbox" name="agentIds[]" value="<?= $agent->id ?>" checked="checked"></td>
                <td><?=$agent->name?></td>
                <td><?=$agent->nome_completo ?></td>
                
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<button type="submit" class="button button--primary">transformar em agentes coletivos</button>

</form>