<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Brazilian Portuguese strings.
 *
 * @package    local_sis_notification
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['actionon'] = '{$a->action}: {$a->name}';
$string['addrule'] = 'Adicionar regra';
$string['addrulepage'] = 'Adicionar regra';
$string['allrules'] = 'Todas as regras';
$string['allstatuses'] = 'Todas as situações';
$string['attempts'] = 'Tentativas';
$string['backtorules'] = 'Voltar às regras';
$string['batchsize'] = 'Tamanho do lote';
$string['batchsize_desc'] = 'Número máximo de usuários processados por execução da tarefa de notificações.';
$string['body'] = 'Mensagem';
$string['cmid'] = 'Atividade';
$string['cmid_help'] = 'Somente atividades deste curso com conclusão de atividade habilitada podem ser escolhidas.';
$string['condition'] = 'Condição';
$string['conditiontype'] = 'Notificar alunos';
$string['conditiontype_activity_incomplete'] = 'que não concluíram uma atividade';
$string['conditiontype_course_incomplete'] = 'que não concluíram o curso';
$string['conditiontype_inactivity'] = 'que não acessaram o curso';
$string['confirmdelete'] = 'Excluir a regra "{$a}"? O histórico de envios dela também é excluído e isso não pode ser desfeito. Para parar os envios sem perder o histórico, pause a regra.';
$string['countnoengine'] = 'A contagem de alunos elegíveis ainda não está disponível.';
$string['countrecipients'] = 'Contar alunos elegíveis';
$string['countrecipients_help'] = 'Conta os alunos que poderiam receber uma mensagem deste curso agora. Pode levar alguns segundos em cursos grandes.';
$string['countresult'] = '{$a->eligible} alunos elegíveis entre {$a->examined} examinados; {$a->temporary} estão temporariamente não elegíveis. Contagem feita em {$a->time}.';
$string['counttruncated'] = 'A contagem é parcial: somente os primeiros {$a->examined} alunos foram examinados. Contagem feita em {$a->time}.';
$string['cyclechangenote'] = 'Alterar a condição, os dias ou a atividade reinicia o ciclo desta regra.';
$string['cyclelockednote'] = 'A condição, os dias e a atividade não podem ser alterados enquanto a regra estiver habilitada. Pause a regra antes.';
$string['days'] = 'Dias';
$string['days_help'] = 'Número de dias (1 a 3650) em que a condição deve se manter antes de a mensagem ser enviada. A contagem nunca começa antes de a regra ser habilitada (ou reiniciada); por isso, alunos que já atendem à condição nesse momento não são notificados de imediato. Inatividade: conta a partir do último acesso ao curso ou, se não houve acesso depois, a partir da mais recente entre a inscrição, a data de início do curso e a ativação da regra. Conclusão do curso: conta a partir da mais recente entre a inscrição, a data de início do curso e a ativação da regra. Conclusão de atividade: conta a partir do primeiro momento em que a atividade fica disponível para o aluno, e nunca antes da inscrição, da data de início do curso ou da ativação da regra.';
$string['defaultbody'] = '<p>Olá, {fullname},</p><p>Percebemos que você não acessa {coursename} há {days} dias. Volte e continue de onde parou: {courseurl}</p>';
$string['defaultsubject'] = 'Sentimos sua falta, {firstname}';
$string['deleterule'] = 'Excluir';
$string['editrule'] = 'Editar';
$string['editrulepage'] = 'Editar regra';
$string['enableonsave'] = 'Habilitar a regra';
$string['enableonsave_check'] = 'Habilitar esta regra assim que for salva';
$string['enablerule'] = 'Habilitar';
$string['error_cmid'] = 'Escolha uma atividade deste curso com conclusão de atividade habilitada.';
$string['error_completioncriteria'] = 'Este curso não tem critérios de conclusão.';
$string['error_completiondisabled'] = 'A conclusão de curso não está habilitada neste curso.';
$string['error_conditiontype'] = 'Escolha uma das condições disponíveis.';
$string['error_cycleactive'] = 'Isto não pode ser alterado enquanto a regra estiver habilitada. Pause a regra antes.';
$string['error_cyclereset'] = 'Marque a caixa para confirmar que a regra vai recomeçar.';
$string['error_maxlength'] = 'Use no máximo {$a} caracteres.';
$string['error_range'] = 'Informe um número inteiro de {$a->min} a {$a->max}.';
$string['error_repeatdays'] = 'Informe 0 para enviar uma vez, ou um número inteiro de 1 a {$a}.';
$string['error_subjectcontrol'] = 'O assunto deve ser uma única linha de texto.';
$string['error_variables'] = 'Variável desconhecida: {$a->unknown}. Variáveis permitidas: {$a->allowed}.';
$string['filter'] = 'Filtrar';
$string['filterrule'] = 'Regra';
$string['filterstatus'] = 'Situação';
$string['general'] = 'Regra';
$string['history'] = 'Histórico de notificações';
$string['historyempty'] = 'Não há envios para exibir.';
$string['historyintro'] = 'Envios aos alunos deste curso. O conteúdo das mensagens não é exibido.';
$string['lastchange'] = 'Última alteração';
$string['maxsends'] = 'Máximo de mensagens por aluno';
$string['maxsends_help'] = 'O total de mensagens (1 a 10) que um aluno pode receber em um ciclo, incluindo a primeira. Só importa quando há repetição.';
$string['message'] = 'Mensagem';
$string['messageprovider:course_notification'] = 'Notificações do curso enviadas por regras';
$string['name'] = 'Nome da regra';
$string['navigationlabel'] = 'Notificações';
$string['norepeatsummary'] = 'Enviada uma única vez';
$string['norules'] = 'Ainda não há regras de notificação neste curso.';
$string['pauserule'] = 'Pausar';
$string['pluginname'] = 'Notificações do curso';
$string['previewalert'] = 'As variáveis foram preenchidas com os seus próprios dados.';
$string['previewcountfailed'] = 'Não foi possível concluir a contagem.';
$string['previewintro'] = 'É assim que a mensagem aparece para você. Nada é enviado.';
$string['previewmessage'] = 'Mensagem';
$string['previewpage'] = 'Prévia: {$a}';
$string['previewrule'] = 'Prévia';
$string['previewsubject'] = 'Assunto';
$string['previewusing'] = 'Prévia gerada com o curso "{$a->course}" e o usuário "{$a->user}".';
$string['privacy:metadata'] = 'O plugin de notificações do curso guarda quais notificações foram enviadas a cada usuário, com a situação e as datas.';
$string['privacy:metadata:core_message'] = 'As notificações são encaminhadas ao sistema de mensagens do Moodle.';
$string['privacy:metadata:count'] = 'Contadores dos ciclos de lembretes e processamento.';
$string['privacy:metadata:createdby'] = 'Autor da regra de notificação.';
$string['privacy:metadata:messageid'] = 'Identificador retornado pela API de mensagens do Moodle.';
$string['privacy:metadata:rule'] = 'Configuração das notificações e autoria das regras.';
$string['privacy:metadata:send'] = 'Reservas de notificações e resultados do processamento.';
$string['privacy:metadata:state'] = 'Estado da inscrição e do ciclo de lembretes do destinatário.';
$string['privacy:metadata:status'] = 'Estado de processamento e códigos de motivo.';
$string['privacy:metadata:time'] = 'Datas usadas para avaliar e agendar lembretes.';
$string['privacy:metadata:userid'] = 'Identificador do destinatário.';
$string['reason'] = 'Motivo';
$string['reason_activity_deleted'] = 'Atividade alvo excluída';
$string['reason_activity_unavailable'] = 'Atividade indisponível para este aluno';
$string['reason_api_exception'] = 'Processador de mensagens retornou resultado desconhecido';
$string['reason_api_result_unknown'] = 'A API de mensagens não confirmou a aceitação';
$string['reason_completed'] = 'Curso ou atividade já concluído';
$string['reason_completion_disabled'] = 'Rastreamento de conclusão ou critérios indisponíveis';
$string['reason_completion_pending'] = 'Aguardando agregação da conclusão do curso';
$string['reason_condition_met'] = 'A condição não se aplica mais';
$string['reason_course_deleted'] = 'Curso excluído';
$string['reason_course_unavailable'] = 'Curso oculto, não iniciado ou encerrado';
$string['reason_cycle_finished'] = 'Ciclo atingiu o limite de notificações';
$string['reason_enrolment_inactive'] = 'Inscrição suspensa ou método de inscrição desabilitado';
$string['reason_invalid_condition'] = 'Condição inválida';
$string['reason_lease_expired'] = 'Processamento interrompido com resultado desconhecido';
$string['reason_max_sends'] = 'Máximo de mensagens atingido';
$string['reason_new_access'] = 'O aluno retornou antes do envio da notificação';
$string['reason_no_permission'] = 'Não pode receber notificações';
$string['reason_no_receive_capability'] = 'Destinatário sem permissão para receber lembretes';
$string['reason_not_enrolled'] = 'Não está mais inscrito';
$string['reason_obsolete'] = 'Reserva pertence a um ciclo anterior';
$string['reason_rule_disabled'] = 'Regra não habilitada';
$string['reason_rule_paused'] = 'Regra pausada';
$string['reason_rule_revised'] = 'Regra reiniciada';
$string['reason_unenrolled'] = 'Sem inscrição ativa dentro do período de validade';
$string['reason_user_deleted'] = 'Conta excluída ou indisponível';
$string['reason_user_inactive'] = 'Conta suspensa ou não confirmada';
$string['reason_user_suspended'] = 'Conta ou inscrição não ativa';
$string['repeatdays'] = 'Repetir a cada (dias)';
$string['repeatdays_help'] = 'Use 0 para enviar a mensagem uma única vez. Caso contrário, a mensagem é enviada de novo após esse número de dias (1 a 3650) enquanto o aluno ainda atender à condição.';
$string['repeatsummary'] = 'Repete a cada {$a->repeatdays} dias, até {$a->maxsends} mensagens';
$string['resetcycle'] = 'Reiniciar o ciclo';
$string['resetcycle_check'] = 'Entendo que a regra vai recomeçar';
$string['resetcycle_help'] = 'Alterar a condição, o número de dias ou a atividade faz a regra recomeçar: os contadores são reiniciados e as mensagens ainda aguardando envio são canceladas. Marque a caixa para confirmar. A regra precisa estar pausada.';
$string['retentiondays'] = 'Retenção do histórico (dias)';
$string['retentiondays_desc'] = 'Por quanto tempo os registros de envio são mantidos antes de serem removidos.';
$string['ruleactions'] = 'Ações';
$string['ruleactivatedat'] = 'Ativa desde {$a}';
$string['rulecreated'] = 'A regra "{$a}" foi criada. Ela está pausada: habilite-a quando estiver pronto.';
$string['rulecreatedenabled'] = 'A regra "{$a}" foi criada e está habilitada.';
$string['ruledeleted'] = 'A regra "{$a}" e o seu histórico de envios foram excluídos.';
$string['ruleenabled'] = 'Habilitada';
$string['ruleenabledmessage'] = 'A regra "{$a}" foi habilitada.';
$string['ruleenablefailed'] = 'A regra "{$a->name}" não pode ser habilitada: {$a->errors}';
$string['rulelocked'] = 'A regra está sendo processada neste momento. Tente novamente em instantes.';
$string['rulename'] = 'Nome da regra';
$string['rulenotfound'] = 'A regra não existe mais.';
$string['rulepaused'] = 'Pausada';
$string['rulepausedmessage'] = 'A regra "{$a}" foi pausada. As mensagens que ainda aguardavam envio foram canceladas.';
$string['rules'] = 'Regras de notificação';
$string['rulesheading'] = 'Regras deste curso';
$string['rulesintro'] = 'As regras enviam uma mensagem aos alunos deste curso. Uma regra nova já nasce habilitada, mas você pode desmarcar a opção ao criá-la ou pausá-la depois.';
$string['rulestatus'] = 'Situação';
$string['rulesummary'] = 'Condição';
$string['rulesummary_activity_incomplete'] = '"{$a->activity}" não concluída {$a->days} dias após ficar disponível';
$string['rulesummary_activity_missing'] = 'uma atividade que não existe mais';
$string['rulesummary_course_incomplete'] = 'Curso não concluído há {$a->days} dias';
$string['rulesummary_inactivity'] = 'Sem acessar o curso há {$a->days} dias';
$string['ruleupdated'] = 'A regra "{$a}" foi salva.';
$string['scheduledfor'] = 'Agendada para';
$string['sending'] = 'Envio';
$string['sequence'] = 'Mensagem';
$string['sequencevalue'] = 'nº {$a->seq} (ciclo {$a->cycle})';
$string['sis_notification:manage'] = 'Gerenciar regras de notificação do curso';
$string['sis_notification:receive'] = 'Receber notificações do curso';
$string['sis_notification:viewhistory'] = 'Ver o histórico de notificações do curso';
$string['status_accepted'] = 'Aceita';
$string['status_accepted_help'] = 'Aceita pelo sistema de mensagens. Isto não confirma que o aluno recebeu ou leu a mensagem.';
$string['status_failed'] = 'Falhou';
$string['status_pending'] = 'Aguardando';
$string['status_processing'] = 'Em processamento';
$string['status_suppressed'] = 'Cancelada';
$string['status_uncertain'] = 'Incerta';
$string['statusnote'] = '"Aceita" significa que a mensagem foi aceita pelo sistema de mensagens; não confirma a entrega ao aluno.';
$string['student'] = 'Aluno';
$string['subject'] = 'Assunto';
$string['task_cleanup_history'] = 'Limpar histórico antigo de notificações';
$string['task_process_rules'] = 'Processar regras de notificação';
$string['taskcleanup'] = 'Limpar histórico de notificações';
$string['taskscan'] = 'Identificar notificações dos cursos';
$string['variables'] = 'Variáveis disponíveis';
$string['variables_activity'] = 'Somente quando a condição é uma atividade: {$a}.';
$string['variables_common'] = 'Use estas variáveis no assunto e na mensagem: {$a}.';
$string['viewhistory'] = 'Ver histórico';
