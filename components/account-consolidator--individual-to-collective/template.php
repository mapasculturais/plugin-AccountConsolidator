<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

use MapasCulturais\i;
?>
<div>
    <p> A lista abaixo é formada pelos agentes do tipo INDIVIDUAL que o plugin identificou, baseado no nome, como provavelmente sendo agentes coletivos.</p>
    <p> Desmarque da lista abaixo os agentes que NÃO DEVEM ser transformados em agentes coletivos.</p>

    <hr>
    
    <form @submit.prevent="post($event)">
        <table>
            <thead>
                <tr style="position: sticky; top:0; background-color: white; border-bottom: 2px solid black">
                    <td>#</td>
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
                    <tr v-for="(agent, index) in agents">
                        <td>{{index}}</td>
                        <td><label :for="id(agent)">{{agent.user_id}}</label></td>
                        <td><label :for="id(agent)">{{agent.id}}</label></td>
                        <td><label :for="id(agent)">{{agent.profile_id ? 'SIM' : ''}}</label></td>
                        <td><label :for="id(agent)">{{agent.cpf}}</label></td>
                        <td><label :for="id(agent)">{{agent.cnpj}}</label></td>
                        <td><input type="checkbox" :id="id(agent)" v-model="agent.checked"></td>
                        <td><label :for="id(agent)">{{agent.name}}</label></td>
                        <td><label :for="id(agent)">{{agent.nome_completo}}</label></td>
                    </tr>
            </tbody>
        </table>
        <button type="submit" class="button button--primary">transformar em agentes coletivos</button>
    </form>
</div>