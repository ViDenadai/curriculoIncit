<?php

namespace IntecPhp\Model;

use IntecPhp\Entity\User;
use IntecPhp\Entity\RequestPassword;
use IntecPhp\Worker\EmailWorker;
use Exception;
use Pheanstalk\Pheanstalk;

//  Classe Access é um Model responsável por tratar do cadastro de um novo usuário, o login e a recuperação de senha
//  está diretamente ligado com as entidades User e RequestPassword
class Access
{
    private $user;
    private $requestPass;
    private $emailProducer;

    public function __construct(
        User $user, 
        RequestPassword $requestPass, 
        Pheanstalk $emailProducer
        )
    {
        $this->user = $user;
        $this->requestPass = $requestPass;
        $this->emailProducer = $emailProducer;
    }

    // Função na Model para criar uma nova conta de usuário
    public function newAccount(
        $name, 
        $email, 
        $identity, 
        $user_type, 
        $password
        )
    {
        // Verifica se já existe o usuário na tabela, em caso de sucesso uma exceção é lançada
        if($this->user->getUserId($email)) {
            throw new Exception('Existe um usuário com esse e-mail');
        }
        
        // Gera um hash para inserir a senha na tabela de usuário
        $hash = password_hash($password, PASSWORD_DEFAULT);

        // Insere o usuário na tabela, em caso de sucesso retorna o id do novo usuário, caso contrário, uma exceção é lançada
        $userId = $this->user->insert($name, $email, $identity, $user_type, $hash);
        if(!$userId) {
            throw new Exception('Não foi possivel fazer o cadastro do usuário');
        }
    }

    // Função na Model para fazer o login do usuário
    public function login($email, $password)
    {
        // Verifica se o email existe, em caso de sucesso retorna o id do usuário, caso contrário, uma exceção é lançada 
        $userId = $this->user->getUserId($email);
        if(!$userId) {
            throw new Exception('Não existe um usuário com esse e-mail');
        }
        // Recupera o hash existente na tabela a partir do email
        $hashFromTable = $this->user->getHash($email);

        // Compara a senha fornecida com o hash existente na tabela de usuário
        // em caso de fracasso, uma exceção é lançada
        if(!password_verify($password, $hashFromTable)) {
            throw new Exception('Senha incorreta. Tente novamente ou clique em esqueci a senha');
        }

        // Em caso de sucesso recupera o tipo do usuário e o id a partir do e-mail, caso contrário, uma exceção é lançada
        $info = $this->user->getInfo($email);
        if(!$info) {
            throw new Exception('O login não ocorreu adequadamente');
        }
        return $info;
    }

    // 1ª Função na Model para recuperar a senha (Responsável por tratar a inserção na tabela request_password, ou seja, a parte que
    // enviará o e-mail ao usuário)
    public function forgotMyPass($email)
    {
        // Verifica se o email existe, em caso de sucesso retorna o id do usuário, caso contrário, nada acontece por
        // questão de segurança
        $userId = $this->user->getUserId($email);
        if($userId) {
            // Recupera o nome do usuário
            $name = $this->user->get($userId);
            $name = $name['name'];
            
            // Gera um hash para a requisição de uma nova senha
            $hash = md5(uniqid(""));

            // Obtenção do dia e hora atual / referência: São Paulo
            date_default_timezone_set('America/Sao_Paulo');

            // Um intervalo de 1 dia é adicionado ao tempo atual
                // mktime - obtém um timestamp Unix do dia atual
            $expDate  = mktime (date("h"), date("i"), date("s"), date("m")  , date("d")+1, date("Y"));
                // Converte o timestamp para o formato de data Y-m-d h:i:s
            $expDate = date('Y-m-d h:i:s', $expDate);

            // Verifica se já existe um registro do usuário que fez a requisição e atualiza o registro se existir, caso contrário, insere um novo registro
                // 1º chama a função getRequest passando ID do usuário, em caso de sucesso retorna o ID do registro
                // 2º Se existir o registro o atualiza
            $id = $this->requestPass->getRequest($userId);
            if($id) {
                $this->requestPass->updateRequest($id, $hash, $expDate);
            } else {
                // Chama a função da entidade RequestPassword para adicionar um registro na tabela request_password
                if(!$this->requestPass->insert($userId, $hash, $expDate)) {
                    throw new Exception('A requisição não ocorreu adequadamente');
                }   
            }
            // Gerar a URL para redefinir a senha
            $url = sprintf('%s%s', 'http://localhost:8080/changeMyPass/', $hash);

            // Envia o e-mail de recuperação
            $this
                ->emailProducer
                ->put(json_encode([
                    'to_name' => 'alguem',
                    'to_email' => 'victord112@gmail.com',
                    'subject_prefix' => '[Recuperação de senha] ',
                    'subject' => "",
                    'body' => sprintf(
                        "Olá %s, 
                        <br /> Recebemos sua solicitação para a alteração da senha cadastrada. Para redefinir sua senha clique no link abaixo: <br /> 
                        %s", 
                        $name, 
                        $url
                        ),
                ]));
        }        
    }

    // 2ª Função na Model para recuperar a senha (Responsável por comparar a hash proveniente do email com as entradas 
    // da tabela request_password e trocar a senha em caso de sucesso na etapa anterior)
    public function changeMyPass(
        $hash, 
        $email, 
        $newPass
        )
    {
        // Busca o id do usuário a partir do email recebido
        $userId = $this->user->getUserId($email);

        // Obtenção do dia e hora atual / referência: São Paulo
        date_default_timezone_set('America/Sao_Paulo');
            // mktime - obtém um timestamp Unix do dia atual
        $currentDate  = mktime (date("h"), date("i"), date("s"), date("m")  , date("d"), date("Y"));
            // Converte o timestamp para o formato de data Y-m-d h:i:s
        $currentDate = date('Y-m-d h:i:s', $currentDate);

        // Chama a função da entidade RequestPassword para comparar a data da requisição com o registro da tabela request_password
        // em caso de sucesso (a data atual se encontra dentro do limite estipulado de 1 dia) retorna true
        if(!$this->requestPass->compareDate($userId, $currentDate)) {
            throw new Exception('A requisição expirou');
        }

        // Chama a função da entidade RequestPassword para comparar o hash com o registro da tabela request_password
        if(!$this->requestPass->compare($userId, $hash)) {
            throw new Exception('A requisição não ocorreu adequadamente');
        } 

        // Gera um hash para inserir a nova senha na tabela de usuário
        $hash = md5($newPass);  
        if(!$this->user->updatePass($userId, $hash)) {
            throw new Exception('A troca de senha não ocorreu adequadamente'); 
        }   
    }    
}
